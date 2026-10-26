<?php declare(strict_types=1);

require_once __DIR__ . '/release-validation-common.php';

/**
 * Local planning only. Package tree IDs are not split commits or verified tags.
 */
final class PackagePublicationPlanner
{
    private readonly ReleaseValidationProcessRunner $runner;

    public function __construct()
    {
        $this->runner = new ReleaseValidationProcessRunner();
    }

    /** @param list<string> $arguments */
    public function plan(array $arguments): array
    {
        $options = $this->parseArguments($arguments);
        $root = $options['root'];
        if (normalizePath((string) realpath($this->git($root, array('rev-parse', '--show-toplevel')))) !== normalizePath($root)) {
            releaseValidationFail('Source root must be the Git checkout root.');
        }

        $before = $this->sourceState($root);
        try {
            if ($before['status'] !== '') {
                releaseValidationFail('Source checkout is dirty; a committed, clean source is required.');
            }
            if (preg_match('/^(?!H )[^\r\n]+/m', $this->git($root, array('ls-files', '-v'))) === 1) {
                releaseValidationFail('Source has hidden index entries (assume-unchanged or skip-worktree).');
            }
            if ($this->git($root, array('for-each-ref', 'refs/replace')) !== '') {
                releaseValidationFail('Source replacement refs are not supported.');
            }
            $branch = $before['branch'];
            if ($branch === 'master' || ($branch !== 'HEAD' && $branch !== '2.x' && !str_starts_with($branch, 'feature/'))) {
                releaseValidationFail('Source branch must be 2.x, a feature branch, or detached HEAD for CI.');
            }
            foreach (array('commit', 'tree', 'branch') as $key) {
                if (isset($options['source-' . $key]) && $options['source-' . $key] !== $before[$key]) {
                    releaseValidationFail('Source ' . $key . ' does not match the requested identity.');
                }
            }

            $packages = loadReleasePackages($root);
            $this->committedContents($root, $before['commit'], 'release-packages.json');
            $destinations = $this->destinations($root, $before['commit'], $packages);
            $entries = $this->treeEntries($root, $before['commit']);
            $planned = array();
            $names = array_column($packages, 'name');

            foreach ($packages as $index => $package) {
                $directory = $package['directory'];
                foreach (array('packages', $directory, $directory . '/composer.json') as $path) {
                    if (is_link(joinPaths($root, $path))) {
                        releaseValidationFail('Package directory or manifest is a symlink: ' . $package['name']);
                    }
                }
                if (($entries[$directory]['type'] ?? null) !== 'tree') {
                    releaseValidationFail('Package directory is not a committed tree: ' . $package['name']);
                }
                foreach ($entries as $path => $entry) {
                    if (str_starts_with($path, $directory . '/') && !in_array($entry['mode'], array('040000', '100644', '100755'), true)) {
                        releaseValidationFail('Package tree contains an unsupported Git entry: ' . $package['name']);
                    }
                }
                foreach (array('composer.json', 'README.md', 'LICENSE.md') as $file) {
                    if (($entries[$directory . '/' . $file]['type'] ?? null) !== 'blob') {
                        releaseValidationFail('Package tree is missing ' . $file . ': ' . $package['name']);
                    }
                }

                $manifest = json_decode($this->committedContents($root, $before['commit'], $directory . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($manifest) || ($manifest['name'] ?? null) !== $package['name']
                    || ($manifest['require']['php'] ?? null) !== '^8.4'
                    || ($manifest['license'] ?? null) !== 'BSD-3-Clause'
                    || array_intersect(array('version', 'minimum-stability', 'prefer-stable'), array_keys($manifest)) !== array()
                ) {
                    releaseValidationFail('Invalid package manifest identity, PHP, licensing or stability metadata: ' . $package['name']);
                }
                foreach (array('require', 'require-dev') as $section) {
                    $requirements = $manifest[$section] ?? array();
                    if (!is_array($requirements)) {
                        releaseValidationFail('Invalid package manifest requirements: ' . $package['name']);
                    }
                    foreach ($requirements as $dependency => $constraint) {
                        if (str_starts_with((string) $dependency, 'evolvephp/')
                            && (!in_array($dependency, $names, true) || $constraint !== '^2.0')
                        ) {
                            releaseValidationFail('Invalid first-party dependency constraint: ' . $package['name']);
                        }
                    }
                }
                $planned[] = array(
                    'name' => $package['name'],
                    'directory' => $directory,
                    'destination' => $destinations[$index]['repository'],
                    'destination_status' => 'proposed-unverified',
                    'package_tree' => $entries[$directory]['object'],
                    'composer_blob' => $entries[$directory . '/composer.json']['object'],
                );
            }

            return array(
                'version' => 1,
                'mode' => 'dry-run',
                'publication_enabled' => false,
                'proposed_version' => '2.0.0-beta.1',
                'source' => array('branch' => $branch, 'commit' => $before['commit'], 'tree' => $before['tree']),
                'split_history_verification' => 'separate release:split:validate required',
                'package_tags' => 'not created or verified',
                'packagist_registration' => 'not performed or verified',
                'packages' => $planned,
            );
        } finally {
            if ($this->sourceState($root) !== $before) {
                releaseValidationFail('Source state changed during release planning.');
            }
        }
    }

    /** @param list<string> $arguments
     * @return array<string, string>
     */
    private function parseArguments(array $arguments): array
    {
        $options = array();
        foreach ($arguments as $argument) {
            if (!preg_match('/^--(root|source-commit|source-tree|source-branch)=(.+)$/D', $argument, $match)
                || isset($options[$match[1]])
            ) {
                releaseValidationFail('Unsupported or duplicate CLI argument.');
            }
            if (in_array($match[1], array('source-commit', 'source-tree'), true) && !preg_match('/^[a-f0-9]{40}$/D', $match[2])) {
                releaseValidationFail('Source identity must be a full SHA-1 object ID.');
            }
            $options[$match[1]] = $match[2];
        }
        $root = realpath($options['root'] ?? repositoryRootDefault());
        if ($root === false || !is_dir($root)) {
            releaseValidationFail('Source root does not exist.');
        }
        $options['root'] = $root;
        return $options;
    }

    /** @param list<string> $arguments */
    private function git(string $root, array $arguments): string
    {
        return rtrim($this->runner->mustRun(
            array_merge(array('git', '--no-optional-locks', '--no-replace-objects', '-c', 'core.fsmonitor=false', '-C', $root), $arguments),
            null,
            array('GIT_TERMINAL_PROMPT' => '0', 'GIT_ALLOW_PROTOCOL' => 'file'),
        )->stdout, "\r\n");
    }

    private function sourceState(string $root): array
    {
        $index = $this->git($root, array('rev-parse', '--path-format=absolute', '--git-path', 'index'));
        $indexHash = is_file($index) ? hash_file('sha256', $index) : false;
        if ($indexHash === false) {
            releaseValidationFail('Source Git index is missing or unreadable.');
        }
        return array(
            'branch' => $this->git($root, array('rev-parse', '--abbrev-ref', 'HEAD')),
            'commit' => $this->git($root, array('rev-parse', '--verify', 'HEAD^{commit}')),
            'tree' => $this->git($root, array('rev-parse', '--verify', 'HEAD^{tree}')),
            'refs' => $this->git($root, array('for-each-ref', '--sort=refname', '--format=%(objectname) %(refname)')),
            'index' => $indexHash,
            'status' => $this->git($root, array('status', '--porcelain=v1', '--untracked-files=all', '--ignore-submodules=none')),
        );
    }

    private function committedContents(string $root, string $commit, string $path): string
    {
        if (!is_file(joinPaths($root, $path)) || is_link(joinPaths($root, $path))) {
            releaseValidationFail('Missing or unsafe ' . $path . '.');
        }
        $committed = $this->git($root, array('show', $commit . ':' . $path));
        $working = file_get_contents(joinPaths($root, $path));
        // Accommodate a native Windows checkout's Git-managed CRLF conversion.
        if ($working === false || rtrim(str_replace("\r\n", "\n", $working), "\n") !== str_replace("\r\n", "\n", $committed)) {
            releaseValidationFail('Working file differs from source commit: ' . $path);
        }
        return $committed;
    }

    /** @param list<array{name: string, directory: string}> $packages
     * @return list<array{name: string, repository: string}>
     */
    private function destinations(string $root, string $commit, array $packages): array
    {
        $map = json_decode($this->committedContents($root, $commit, 'tools/release-package-destinations.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($map) || array_keys($map) !== array('version', 'status', 'packages')
            || $map['version'] !== 1 || $map['status'] !== 'proposed-unverified'
            || !is_array($map['packages']) || !array_is_list($map['packages']) || count($map['packages']) !== 34
        ) {
            releaseValidationFail('Invalid destinations: exactly 34 ordered proposed mappings are required.');
        }
        $seen = array();
        foreach ($packages as $index => $package) {
            $destination = $map['packages'][$index];
            if (!is_array($destination) || array_keys($destination) !== array('name', 'repository')
                || $destination['name'] !== $package['name']
                || !is_string($destination['repository'])
                || !preg_match('~^josiahking/evolvephp-[a-z0-9]+(?:-[a-z0-9]+)*$~D', $destination['repository'])
                || $destination['repository'] !== 'josiahking/evolvephp-' . packageSlug($package['name'])
                || isset($seen[$destination['repository']])
            ) {
                releaseValidationFail('Invalid, duplicate or unapproved destination mapping at position ' . ($index + 1) . '.');
            }
            $seen[$destination['repository']] = true;
        }
        return $map['packages'];
    }

    /** @return array<string, array{mode: string, type: string, object: string}> */
    private function treeEntries(string $root, string $commit): array
    {
        $entries = array();
        foreach (explode("\0", rtrim($this->git($root, array('ls-tree', '-r', '-t', '-z', $commit)), "\0")) as $line) {
            if (!preg_match('/^([0-9]{6}) (blob|tree|commit) ([a-f0-9]{40})\t(.+)$/sD', $line, $match)) {
                releaseValidationFail('Invalid source tree entry.');
            }
            $entries[$match[4]] = array('mode' => $match[1], 'type' => $match[2], 'object' => $match[3]);
        }
        return $entries;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $plan = (new PackagePublicationPlanner())->plan(array_slice($argv, 1));
        echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    } catch (ReleaseValidationFailure | JsonException $failure) {
        fwrite(STDERR, 'EvolvePHP release plan failed: ' . ($failure instanceof JsonException ? 'Invalid JSON: ' : '') . $failure->getMessage() . PHP_EOL);
        exit(1);
    }
}
