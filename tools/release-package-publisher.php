<?php declare(strict_types=1);

require_once __DIR__ . '/release-validation-common.php';

/** Offline preparation only: no credential handling or publication implementation. */
final class PackagePublisherPreparation
{
    private string $root;

    /** @param list<string> $arguments */
    public function plan(array $arguments): array
    {
        $options = $this->options($arguments);
        $this->root = $options['root'];
        if (normalizePath($this->git(array('rev-parse', '--show-toplevel'))) !== normalizePath($this->root)) {
            releaseValidationFail('Source root must be the checkout root.');
        }
        // Status can invoke clean filters. Refuse these before inspecting working files.
        $config = $this->git(array('config', '--local', '--name-only', '--list'));
        if (preg_match('/^(filter\.|include\.|includeif\.|extensions\.worktreeconfig$)/mi', $config)) {
            releaseValidationFail('Source configuration contains unsupported filters, includes or worktree configuration.');
        }
        $before = $this->state();
        try {
            if ($before['status'] !== '') {
                releaseValidationFail('Source checkout is dirty.');
            }
            if (preg_match('/^(?!H )[^\r\n]+/m', $this->git(array('ls-files', '-v')))) {
                releaseValidationFail('Source has hidden index entries.');
            }
            if ($this->git(array('for-each-ref', 'refs/replace')) !== ''
                || $this->git(array('rev-parse', '--is-shallow-repository')) !== 'false') {
                releaseValidationFail('Replacement refs and shallow source history are unsupported.');
            }
            foreach (array('branch', 'commit', 'tree') as $key) {
                if ($before[$key] !== $options['source-' . $key]) {
                    releaseValidationFail('Source ' . $key . ' does not match the requested identity.');
                }
            }
            if ($before['branch'] !== '2.x' && $before['branch'] !== 'HEAD'
                && !str_starts_with($before['branch'], 'feature/')) {
                releaseValidationFail('Source branch is outside the EvolvePHP 2 preparation boundary.');
            }
            $source = array_intersect_key($before, array_flip(array('branch', 'commit', 'tree')));
            $entries = $this->entries($before['commit']);
            $inventory = $this->contents($entries, 'release-packages.json');
            $packages = loadReleasePackages($this->root);
            if (json_decode($inventory, true, 512, JSON_THROW_ON_ERROR)['packages'] !== $packages) {
                releaseValidationFail('Canonical inventory differs from committed source.');
            }
            $map = json_decode($this->contents($entries, 'tools/release-package-destinations.json'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($map) || array_keys($map) !== array('version', 'status', 'packages')
                || $map['version'] !== 1 || $map['status'] !== 'proposed-unverified'
                || !is_array($map['packages']) || !array_is_list($map['packages']) || count($map['packages']) !== 34) {
                releaseValidationFail('Invalid destination inventory or unapproved ownership status.');
            }
            $license = $this->contents($entries, 'LICENSE.md');
            $planned = $seen = array();
            foreach ($packages as $index => $package) {
                $name = $package['name'];
                $directory = $package['directory'];
                $destination = $map['packages'][$index];
                if (!is_array($destination) || array_keys($destination) !== array('name', 'repository')
                    || $destination['name'] !== $name
                    || $destination['repository'] !== 'josiahking/evolvephp-' . packageSlug($name)) {
                    releaseValidationFail('Invalid, reordered or duplicate destination.');
                }
                if (($entries[$directory]['type'] ?? null) !== 'tree') {
                    releaseValidationFail('Package is not a committed tree.');
                }
                foreach ($entries as $path => $entry) {
                    if (str_starts_with($path, $directory . '/')) {
                        $this->safeEntry($path, $entry);
                    }
                }
                $manifest = json_decode($this->contents($entries, $directory . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
                $this->contents($entries, $directory . '/README.md');
                if ($this->contents($entries, $directory . '/LICENSE.md') !== $license) {
                    releaseValidationFail('Package license differs from source license.');
                }
                if (!is_array($manifest) || ($manifest['name'] ?? null) !== $name
                    || ($manifest['license'] ?? null) !== 'BSD-3-Clause'
                    || ($manifest['require']['php'] ?? null) !== '^8.4'
                    || array_intersect(array('version', 'minimum-stability', 'prefer-stable'), array_keys($manifest)) !== array()) {
                    releaseValidationFail('Invalid package manifest identity, license, PHP or stability.');
                }
                $dependencies = array();
                foreach (array('require', 'require-dev') as $section) {
                    $requirements = $manifest[$section] ?? array();
                    if (!is_array($requirements)) {
                        releaseValidationFail('Invalid manifest requirements.');
                    }
                    foreach ($requirements as $dependency => $constraint) {
                        if (str_starts_with((string) $dependency, 'evolvephp/')) {
                            if ($constraint !== '^2.0' || !in_array($dependency, array_column($packages, 'name'), true)) {
                                releaseValidationFail('Invalid first-party dependency constraint.');
                            }
                            // Development requirements do not determine consumer installation order.
                            if ($section === 'require') {
                                if (!isset($seen[$dependency])) {
                                    releaseValidationFail('Invalid runtime dependency order or cycle.');
                                }
                                $dependencies[] = $dependency;
                            }
                        }
                    }
                }
                sort($dependencies, SORT_STRING);
                $seen[$name] = true;
                $planned[] = array(
                    'name' => $name,
                    'directory' => $directory,
                    'destination' => $destination['repository'],
                    'destination_status' => 'proposed-unverified',
                    'dependencies' => $dependencies,
                    'gate' => $index === 0 ? 'pilot-approval-required' : 'pilot-acceptance-and-later-approval-required',
                    'package_tree' => $entries[$directory]['object'],
                    'composer_blob' => $entries[$directory . '/composer.json']['object'],
                    'license_blob' => $entries[$directory . '/LICENSE.md']['object'],
                    'split_commit' => null,
                    'verified_tag_object' => null,
                    'required_attestation' => array(
                        'source' => $source,
                        'package' => $name,
                        'destination' => $destination['repository'],
                        'split_tree' => $entries[$directory]['object'],
                        'split_commit' => 'independent deterministic split-history validation required',
                        'tag_ref' => 'refs/tags/2.0.0-beta.1',
                        'tag_target' => 'must peel to the independently verified split commit',
                    ),
                );
            }
            [$observation, $observationHash] = $this->observations($options['observations'] ?? null, $source, $planned);
            $plan = array(
                'version' => 1,
                'mode' => 'dry-run',
                'publication_enabled' => false,
                'publication_readiness' => 'blocked',
                'proposed_version' => '2.0.0-beta.1',
                'source' => $source,
                'pilot' => 'evolvephp/contracts',
                'blockers' => array('destination-ownership-unverified', 'split-and-tag-attestations-unverified', 'explicit-publication-authorization-missing', 'publication-executor-not-implemented'),
                'credential_policy' => 'credentials are neither read nor accepted as authorization',
                'remote_observation' => $observation,
                'observations_sha256' => $observationHash,
                'recovery' => 'stop on partial or conflicting state; independently reconcile every destination and tag before a new approved attempt; never overwrite',
                'packages' => $planned,
            );
            $plan['plan_sha256'] = hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return $plan;
        } finally {
            if ($this->state() !== $before) {
                releaseValidationFail('Source state changed during preparation.');
            }
        }
    }

    private function options(array $arguments): array
    {
        $options = array();
        foreach ($arguments as $argument) {
            if ($argument === '--publish') {
                releaseValidationFail('Publication disabled: verified ownership, explicit publication authorization and a separately reviewed credential boundary are required.');
            }
            if (!preg_match('/^--(root|source-commit|source-tree|source-branch|observations)=([^\x00-\x1f\x7f]+)$/D', $argument, $match)
                || isset($options[$match[1]])) {
                releaseValidationFail('Unsupported or duplicate CLI argument.');
            }
            $options[$match[1]] = $match[2];
        }
        foreach (array('commit', 'tree', 'branch') as $key) {
            if (!isset($options['source-' . $key])) {
                releaseValidationFail('Explicit source identity requires commit, tree and branch.');
            }
        }
        foreach (array('commit', 'tree') as $key) {
            if (!preg_match('/^[a-f0-9]{40}$/D', $options['source-' . $key])) {
                releaseValidationFail('Source identity requires full SHA-1 object IDs.');
            }
        }
        $root = realpath($options['root'] ?? repositoryRootDefault());
        if ($root === false || !is_dir($root)) {
            releaseValidationFail('Source root does not exist.');
        }
        $options['root'] = $root;
        return $options;
    }

    /** Only fixed read-only subcommands reach this method. No temp files or inherited Git credentials/config. */
    private function git(array $arguments, bool $trim = true): string
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $environment = array();
        foreach (array('PATH', 'SystemRoot', 'WINDIR', 'SYSTEMDRIVE') as $key) {
            $value = getenv($key);
            if ($value !== false) { $environment[$key] = $value; }
        }
        $environment += array('GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => $null,
            'GIT_TERMINAL_PROMPT' => '0', 'GIT_ALLOW_PROTOCOL' => '', 'GIT_NO_LAZY_FETCH' => '1');
        $command = array_merge(array('git', '--no-optional-locks', '--no-replace-objects',
            '-c', 'core.fsmonitor=false', '-c', 'core.untrackedCache=false', '-c', 'core.attributesFile=' . $null,
            '-c', 'protocol.allow=never', '-C', $this->root), $arguments);
        // Discard child diagnostics: configuration and errors can contain sensitive remote URLs.
        $process = proc_open($command, array(0 => array('file', $null, 'r'), 1 => array('pipe', 'w'), 2 => array('file', $null, 'w')), $pipes, null, $environment, array('bypass_shell' => true));
        if (!is_resource($process)) {
            releaseValidationFail('Unable to start read-only Git inspection.');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || $output === false) {
            releaseValidationFail('Read-only Git inspection failed.');
        }
        return $trim ? rtrim($output, "\r\n") : $output;
    }

    private function state(): array
    {
        $index = $this->git(array('rev-parse', '--path-format=absolute', '--git-path', 'index'));
        if (!is_file($index) || is_link($index)) {
            releaseValidationFail('Source index is missing or unsafe.');
        }
        return array(
            'branch' => $this->git(array('rev-parse', '--abbrev-ref', 'HEAD')),
            'commit' => $this->git(array('rev-parse', '--verify', 'HEAD^{commit}')),
            'tree' => $this->git(array('rev-parse', '--verify', 'HEAD^{tree}')),
            'index' => hash_file('sha256', $index),
            'refs' => $this->git(array('for-each-ref', '--sort=refname', '--format=%(objectname) %(refname)')),
            'status' => $this->git(array('status', '--porcelain=v1', '--untracked-files=all', '--ignore-submodules=none')),
        );
    }

    private function entries(string $commit): array
    {
        $entries = array();
        foreach (explode("\0", rtrim($this->git(array('ls-tree', '-r', '-t', '-z', $commit), false), "\0")) as $line) {
            if (!preg_match('/^([0-9]{6}) (blob|tree|commit) ([a-f0-9]{40})\t(.+)$/sD', $line, $match)) {
                releaseValidationFail('Unsupported source tree object.');
            }
            $entries[$match[4]] = array('mode' => $match[1], 'type' => $match[2], 'object' => $match[3]);
        }
        return $entries;
    }

    private function safeEntry(string $path, array $entry): void
    {
        if (!preg_match('~^[a-zA-Z0-9_./-]+$~D', $path)
            || !in_array($entry['mode'], array('040000', '100644', '100755'), true)) {
            releaseValidationFail('Unsafe Git path, symlink or unsupported object.');
        }
        $current = $this->root;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_ends_with($segment, '.')
                || preg_match('/^(?:\.git|con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $segment)) {
                releaseValidationFail('Unsafe Git path segment.');
            }
            $current .= '/' . $segment;
            if (is_link($current)) {
                releaseValidationFail('Source path is a symlink.');
            }
        }
    }

    private function contents(array $entries, string $path): string
    {
        $entry = $entries[$path] ?? null;
        if ($entry === null || $entry['type'] !== 'blob') {
            releaseValidationFail('Required source metadata is missing.');
        }
        $this->safeEntry($path, $entry);
        $committed = $this->git(array('cat-file', 'blob', $entry['object']), false);
        $working = file_get_contents($this->root . '/' . $path);
        if ($working === false || str_replace("\r\n", "\n", $working) !== str_replace("\r\n", "\n", $committed)) {
            releaseValidationFail('Working metadata differs from source commit.');
        }
        return $committed;
    }

    /** Observations are untrusted reports, never verified remote identity or permission. */
    private function observations(?string $path, array $source, array $packages): array
    {
        if ($path === null) { return array('not-inspected', null); }
        if (!is_file($path) || is_link($path) || filesize($path) > 1048576) {
            releaseValidationFail('Missing or unsafe observations file.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) { releaseValidationFail('Cannot read observations.'); }
        $report = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($report) || array_keys($report) !== array('version', 'source', 'packages')
            || $report['version'] !== 1 || $report['source'] !== $source
            || !is_array($report['packages']) || !array_is_list($report['packages']) || count($report['packages']) !== 34) {
            releaseValidationFail('Invalid observations inventory or source identity.');
        }
        $complete = 0;
        foreach ($packages as $index => $package) {
            $entry = $report['packages'][$index];
            if (!is_array($entry) || array_keys($entry) !== array('name', 'destination', 'split_commit', 'split_tree', 'remote_commit', 'tag_ref', 'tag_object', 'tag_commit')
                || $entry['name'] !== $package['name'] || $entry['destination'] !== $package['destination']
                || $entry['tag_ref'] !== 'refs/tags/2.0.0-beta.1') {
                releaseValidationFail('Conflicting, duplicate or unexpected destination/tag observation.');
            }
            $ids = array_intersect_key($entry, array_flip(array('split_commit', 'split_tree', 'remote_commit', 'tag_object', 'tag_commit')));
            $present = array_filter($ids, static fn ($id) => $id !== null);
            if ($present === array()) { continue; }
            if (count($present) !== 5) { releaseValidationFail('Partial publication observation; stop and reconcile.'); }
            foreach ($present as $id) {
                if (!is_string($id) || !preg_match('/^[a-f0-9]{40}$/D', $id) || $id === str_repeat('0', 40)) {
                    releaseValidationFail('Invalid observed object identity.');
                }
            }
            if ($entry['split_tree'] !== $package['package_tree'] || $entry['remote_commit'] !== $entry['split_commit']
                || $entry['tag_commit'] !== $entry['split_commit']) {
                releaseValidationFail('Conflicting split, remote or tag identity; stop and reconcile.');
            }
            ++$complete;
        }
        if ($complete !== 0 && $complete !== 34) {
            releaseValidationFail('Partial publication observation; stop and reconcile.');
        }
        return array($complete === 0 ? 'reported-absent-unverified' : 'reported-complete-unverified', hash('sha256', $bytes));
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $plan = (new PackagePublisherPreparation())->plan(array_slice($argv, 1));
        echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    } catch (Throwable $failure) {
        // Do not echo arbitrary parser, filesystem or process diagnostics.
        $message = $failure instanceof ReleaseValidationFailure ? $failure->getMessage() : 'Invalid preparation input.';
        fwrite(STDERR, 'EvolvePHP publisher preparation failed: ' . $message . PHP_EOL);
        exit(1);
    }
}
