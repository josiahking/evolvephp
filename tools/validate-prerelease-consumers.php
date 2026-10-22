<?php declare(strict_types=1);

require_once __DIR__ . '/release-validation-common.php';

final class PrereleaseConsumerValidator
{
    private const ALPHA_VERSION = '2.0.0-alpha.1';
    private const STABLE_VERSION = '2.0.0';

    private ReleaseValidationProcessRunner $runner;

    public function __construct()
    {
        $this->runner = new ReleaseValidationProcessRunner();
    }

    /**
     * @return list<array{name: string, outcome: string, detail: string}>
     */
    public function validate(string $root, string $ref, ?string $composer): array
    {
        $packages = loadReleasePackages($root);
        $lockedRuntimePackages = loadLockedRuntimePackageRepositoryPackages($root);
        $sourceState = captureSourceState($this->runner, $root);
        $temp = createTemporaryDirectory();

        try {
            $sourceSha = trim($this->runner->mustRun(array('git', '-C', $root, 'rev-parse', $ref))->stdout);
            $clone = $temp->child('monorepo');

            $this->runner->mustRun(array('git', 'clone', '--no-hardlinks', $root, $clone));
            $this->runner->mustRun(array('git', '-C', $clone, 'checkout', '--detach', $sourceSha));

            $splitRoots = $this->materializeSplitRoots($temp, $clone, $sourceSha, $packages);
            $alphaRepositories = $this->createTaggedRepositories($temp->child('alpha-repositories'), $splitRoots, self::ALPHA_VERSION);
            $stableRepositories = $this->createTaggedRepositories($temp->child('stable-repositories'), $splitRoots, self::STABLE_VERSION);

            $results = array();
            $results[] = $this->runExpectedFailureCase($temp, $composer, 'Alpha case A', $alphaRepositories, $lockedRuntimePackages, array('evolvephp/http' => '2.0.0-alpha.1'));
            $results[] = $this->runExpectedFailureCase($temp, $composer, 'Alpha case B', $alphaRepositories, $lockedRuntimePackages, array('evolvephp/http' => '^2.0@alpha'));
            $results[] = $this->runExpectedSuccessCase($temp, $composer, 'Alpha case C', $alphaRepositories, $lockedRuntimePackages, array('evolvephp/http' => '^2.0'), array('minimum-stability' => 'alpha', 'prefer-stable' => true), array(
                'evolvephp/contracts' => self::ALPHA_VERSION,
                'evolvephp/core' => self::ALPHA_VERSION,
                'evolvephp/http' => self::ALPHA_VERSION,
            ));
            $results[] = $this->runExpectedSuccessCase($temp, $composer, 'Alpha case D', $alphaRepositories, $lockedRuntimePackages, array(
                'evolvephp/contracts' => '^2.0@alpha',
                'evolvephp/core' => '^2.0@alpha',
                'evolvephp/http' => '^2.0@alpha',
            ), array(), array(
                'evolvephp/contracts' => self::ALPHA_VERSION,
                'evolvephp/core' => self::ALPHA_VERSION,
                'evolvephp/http' => self::ALPHA_VERSION,
            ));
            $results[] = $this->runExpectedFailureCase($temp, $composer, 'Full-graph case E', $alphaRepositories, $lockedRuntimePackages, array(
                'evolvephp/bridge-contracts' => '^2.0@alpha',
                'evolvephp/bridge-psr' => '^2.0@alpha',
                'evolvephp/bridge-laravel' => '^2.0@alpha',
                'evolvephp/bridge-symfony' => '^2.0@alpha',
                'evolvephp/bridge-remote' => '^2.0@alpha',
                'evolvephp/database-contracts' => '^2.0@alpha',
                'evolvephp/database-pdo' => '^2.0@alpha',
                'evolvephp/cache-memory' => '^2.0@alpha',
                'evolvephp/session-contracts' => '^2.0@alpha',
                'evolvephp/lock-contracts' => '^2.0@alpha',
                'evolvephp/queue-contracts' => '^2.0@alpha',
                'evolvephp/queue-memory' => '^2.0@alpha',
                'evolvephp/storage-contracts' => '^2.0@alpha',
                'evolvephp/storage-local' => '^2.0@alpha',
                'evolvephp/secret-contracts' => '^2.0@alpha',
                'evolvephp/dev-tools' => '^2.0@alpha',
                'evolvephp/insight' => '^2.0@alpha',
                'evolvephp/job' => '^2.0@alpha',
                'evolvephp/scheduler' => '^2.0@alpha',
                'evolvephp/migration' => '^2.0@alpha',
                'evolvephp/view' => '^2.0@alpha',
                'evolvephp/view-twig' => '^2.0@alpha',
                'evolvephp/view-blade' => '^2.0@alpha',
                'evolvephp/i18n' => '^2.0@alpha',
                'evolvephp/http-client' => '^2.0@alpha',
                'evolvephp/observe' => '^2.0@alpha',
                'evolvephp/mcp' => '^2.0@alpha',
                'evolvephp/ai' => '^2.0@alpha',
                'evolvephp/testing' => '^2.0@alpha',
            ));
            $results[] = $this->runExpectedSuccessCase($temp, $composer, 'Full-graph case F', $alphaRepositories, $lockedRuntimePackages, array(
                'evolvephp/bridge-contracts' => '^2.0',
                'evolvephp/bridge-psr' => '^2.0',
                'evolvephp/bridge-laravel' => '^2.0',
                'evolvephp/bridge-symfony' => '^2.0',
                'evolvephp/bridge-remote' => '^2.0',
                'evolvephp/database-contracts' => '^2.0',
                'evolvephp/database-pdo' => '^2.0',
                'evolvephp/cache-memory' => '^2.0',
                'evolvephp/session-contracts' => '^2.0',
                'evolvephp/lock-contracts' => '^2.0',
                'evolvephp/queue-contracts' => '^2.0',
                'evolvephp/queue-memory' => '^2.0',
                'evolvephp/storage-contracts' => '^2.0',
                'evolvephp/storage-local' => '^2.0',
                'evolvephp/secret-contracts' => '^2.0',
                'evolvephp/dev-tools' => '^2.0',
                'evolvephp/insight' => '^2.0',
                'evolvephp/job' => '^2.0',
                'evolvephp/scheduler' => '^2.0',
                'evolvephp/migration' => '^2.0',
                'evolvephp/view' => '^2.0',
                'evolvephp/view-twig' => '^2.0',
                'evolvephp/view-blade' => '^2.0',
                'evolvephp/i18n' => '^2.0',
                'evolvephp/http-client' => '^2.0',
                'evolvephp/observe' => '^2.0',
                'evolvephp/mcp' => '^2.0',
                'evolvephp/ai' => '^2.0',
                'evolvephp/testing' => '^2.0',
            ), array('minimum-stability' => 'alpha', 'prefer-stable' => true), $this->expectedVersions($packages, self::ALPHA_VERSION));
            $results[] = $this->runExpectedSuccessCase($temp, $composer, 'Full-graph case G', $alphaRepositories, $lockedRuntimePackages, $this->explicitAlphaRootRequirements($packages), array(), $this->expectedVersions($packages, self::ALPHA_VERSION));
            $results[] = $this->runExpectedSuccessCase($temp, $composer, 'Stable case H', $stableRepositories, $lockedRuntimePackages, array(
                'evolvephp/bridge-contracts' => '^2.0',
                'evolvephp/bridge-psr' => '^2.0',
                'evolvephp/bridge-laravel' => '^2.0',
                'evolvephp/bridge-symfony' => '^2.0',
                'evolvephp/bridge-remote' => '^2.0',
                'evolvephp/database-contracts' => '^2.0',
                'evolvephp/database-pdo' => '^2.0',
                'evolvephp/cache-memory' => '^2.0',
                'evolvephp/session-contracts' => '^2.0',
                'evolvephp/lock-contracts' => '^2.0',
                'evolvephp/queue-contracts' => '^2.0',
                'evolvephp/queue-memory' => '^2.0',
                'evolvephp/storage-contracts' => '^2.0',
                'evolvephp/storage-local' => '^2.0',
                'evolvephp/secret-contracts' => '^2.0',
                'evolvephp/dev-tools' => '^2.0',
                'evolvephp/insight' => '^2.0',
                'evolvephp/job' => '^2.0',
                'evolvephp/scheduler' => '^2.0',
                'evolvephp/migration' => '^2.0',
                'evolvephp/view' => '^2.0',
                'evolvephp/view-twig' => '^2.0',
                'evolvephp/view-blade' => '^2.0',
                'evolvephp/i18n' => '^2.0',
                'evolvephp/http-client' => '^2.0',
                'evolvephp/observe' => '^2.0',
                'evolvephp/mcp' => '^2.0',
                'evolvephp/ai' => '^2.0',
                'evolvephp/testing' => '^2.0',
            ), array(), $this->expectedVersions($packages, self::STABLE_VERSION));

            $results[] = $this->runMcpIndependentConsumer($temp, $composer, $alphaRepositories, $lockedRuntimePackages, $root);

            assertSourceStatePreserved($this->runner, $root, $sourceState);

            return $results;
        } finally {
            $temp->cleanup();
        }
    }

    /**
     * @param list<array{name: string, directory: string}> $packages
     * @return array<string, string>
     */
    private function materializeSplitRoots(ReleaseValidationTemporaryDirectory $temp, string $clone, string $sourceSha, array $packages): array
    {
        $roots = array();

        foreach ($packages as $package) {
            $slug = packageSlug($package['name']);
            $split = trim($this->runner->mustRun(array('git', '-C', $clone, 'subtree', 'split', '--prefix=' . $package['directory'], $sourceSha))->stdout);
            $root = $temp->child('split-roots/' . $slug);

            $this->runner->mustRun(array('git', 'clone', '--no-hardlinks', $clone, $root));
            $this->runner->mustRun(array('git', '-C', $root, 'checkout', '--detach', $split));

            $roots[$package['name']] = $root;
        }

        return $roots;
    }

    /**
     * @param array<string, string> $splitRoots
     * @return array<string, string>
     */
    private function createTaggedRepositories(string $repositoryRoot, array $splitRoots, string $tag): array
    {
        if (!mkdir($repositoryRoot, 0777, true) && !is_dir($repositoryRoot)) {
            releaseValidationFail('Unable to create temporary package repository root: ' . $repositoryRoot);
        }

        $repositories = array();

        foreach ($splitRoots as $packageName => $splitRoot) {
            $repository = joinPaths($repositoryRoot, packageSlug($packageName));

            $this->runner->mustRun(array('git', 'clone', '--no-hardlinks', '--no-tags', $splitRoot, $repository));
            $this->runner->mustRun(array('git', '-C', $repository, 'tag', $tag));
            $this->runner->mustRun(array('git', '-C', $repository, 'tag', '--list', $tag));

            $repositories[$packageName] = $repository;
        }

        return $repositories;
    }

    /**
     * @param array<string, string> $repositories
     * @param list<array<string, mixed>> $lockedRuntimePackages
     * @param array<string, string> $requirements
     * @return array{name: string, outcome: string, detail: string}
     */
    private function runExpectedFailureCase(ReleaseValidationTemporaryDirectory $temp, ?string $composer, string $caseName, array $repositories, array $lockedRuntimePackages, array $requirements): array
    {
        $consumer = $this->createConsumer($temp, $caseName, $repositories, $lockedRuntimePackages, $requirements, array());
        $result = $this->runComposerUpdate($consumer, $temp, $composer);
        $output = $result->output();

        if ($result->exitCode === 0) {
            releaseValidationFail($caseName . ' unexpectedly succeeded.');
        }

        if (!$this->isMinimumStabilityFailure($output)) {
            releaseValidationFail($caseName . ' failed, but not as a transitive minimum-stability rejection: ' . $output);
        }

        return array('name' => $caseName, 'outcome' => 'expected failure', 'detail' => 'transitive minimum-stability rejection');
    }

    /**
     * @param array<string, string> $repositories
     * @param list<array<string, mixed>> $lockedRuntimePackages
     * @param array<string, string> $requirements
     * @param array<string, string|bool> $consumerOptions
     * @param array<string, string> $expectedVersions
     * @return array{name: string, outcome: string, detail: string}
     */
    private function runExpectedSuccessCase(ReleaseValidationTemporaryDirectory $temp, ?string $composer, string $caseName, array $repositories, array $lockedRuntimePackages, array $requirements, array $consumerOptions, array $expectedVersions): array
    {
        $consumer = $this->createConsumer($temp, $caseName, $repositories, $lockedRuntimePackages, $requirements, $consumerOptions);
        $result = $this->runComposerUpdate($consumer, $temp, $composer);

        if ($result->exitCode !== 0) {
            releaseValidationFail($caseName . ' failed unexpectedly: ' . $result->output());
        }

        $actualVersions = $this->readLockedFirstPartyVersions(joinPaths($consumer, 'composer.lock'));

        if ($actualVersions !== $expectedVersions) {
            releaseValidationFail($caseName . ' selected unexpected package versions. expected ' . json_encode($expectedVersions) . ', actual ' . json_encode($actualVersions) . '.');
        }

        return array('name' => $caseName, 'outcome' => 'success', 'detail' => $this->formatVersions($actualVersions));
    }

    /**
     * Install a real disposable consumer after the existing lock-only cases.
     * This is part of the later immutable-ref release gate and is not run during implementation.
     * @param array<string, string> $repositories
     * @param list<array<string, mixed>> $lockedRuntimePackages
     * @return array{name: string, outcome: string, detail: string}
     */
    private function runMcpIndependentConsumer(ReleaseValidationTemporaryDirectory $temp, ?string $composer, array $repositories, array $lockedRuntimePackages, string $root): array
    {
        $offlineRepository = $this->prepareMcpOfflineRepository($root, $temp, $lockedRuntimePackages);
        $firstPartyRepository = $this->prepareMcpFirstPartyOfflineRepository($temp, $repositories);
        $consumer = $this->createConsumer(
            $temp,
            'MCP independent case',
            $repositories,
            $lockedRuntimePackages,
            array('evolvephp/mcp' => '^2.0@alpha', 'guzzlehttp/psr7' => '^3.1'),
            array('minimum-stability' => 'alpha', 'prefer-stable' => true),
            $offlineRepository,
            $firstPartyRepository,
        );
        $update = $this->runComposerUpdate($consumer, $temp, $composer);
        if ($update->exitCode !== 0) {
            releaseValidationFail('MCP independent lock failed: ' . $update->output());
        }
        $install = $this->runner->run(
            withComposerCommand(array(
                '--working-dir=' . $consumer,
                'install',
                '--no-interaction',
                '--no-plugins',
                '--no-scripts',
                '--no-ansi',
            ), $composer),
            null,
            array(
                'COMPOSER_DISABLE_NETWORK' => '1',
                'COMPOSER_HOME' => $temp->child('composer-home'),
                'COMPOSER_CACHE_DIR' => $temp->child('composer-cache'),
            ),
        );
        if ($install->exitCode !== 0) {
            releaseValidationFail('MCP independent install failed: ' . $install->output());
        }
        $fixture = $this->runner->run(
            array(PHP_BINARY, joinPaths($root, 'tools/fixtures/mcp-independent-consumer.php'), $consumer),
            null,
            array('COMPOSER_DISABLE_NETWORK' => '1'),
        );
        if ($fixture->exitCode !== 0) {
            releaseValidationFail('MCP independent fixture failed: ' . $fixture->output());
        }
        return array('name' => 'MCP independent case', 'outcome' => 'success', 'detail' => trim($fixture->stdout));
    }

    /**
     * @param list<array<string, mixed>> $lockedRuntimePackages
     * @return array{root: string, versions: array<string, string>}
     */
    private function prepareMcpOfflineRepository(string $root, ReleaseValidationTemporaryDirectory $temp, array $lockedRuntimePackages): array
    {
        $locked = array_column($lockedRuntimePackages, null, 'name');
        $firstParty = array_column(loadReleasePackages($root), null, 'name');
        $queue = array('evolvephp/mcp', 'guzzlehttp/psr7');
        $visited = array();
        $versions = array();

        while ($queue !== array()) {
            $name = array_shift($queue);
            if (isset($visited[$name])) {
                continue;
            }
            if (!preg_match('~^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$~', $name)) {
                releaseValidationFail('Unsafe MCP offline package name: ' . (string) $name);
            }
            $visited[$name] = true;

            if (str_starts_with($name, 'evolvephp/')) {
                if (!isset($firstParty[$name])) {
                    releaseValidationFail('Unknown MCP first-party package: ' . $name);
                }
                $manifest = readJsonFile(joinPaths($root, $firstParty[$name]['directory'] . '/composer.json'), $name . ' composer.json');
                if (($manifest['name'] ?? null) !== $name) {
                    releaseValidationFail('MCP first-party package name mismatch: ' . $name);
                }
                $requires = $manifest['require'] ?? array();
            } else {
                if (!isset($locked[$name])) {
                    releaseValidationFail('MCP offline dependency is missing from composer.lock: ' . $name);
                }
                $package = $locked[$name];
                if (($package['name'] ?? null) !== $name || !isset($package['version']) || !is_string($package['version'])) {
                    releaseValidationFail('Invalid locked MCP offline package metadata: ' . $name);
                }
                $versions[$name] = $package['version'];
                $requires = $package['require'] ?? array();
            }

            if (!is_array($requires)) {
                releaseValidationFail('Invalid MCP offline package requirements: ' . $name);
            }
            foreach (array_keys($requires) as $dependency) {
                if (is_string($dependency) && str_contains($dependency, '/')) {
                    $queue[] = $dependency;
                }
            }
        }

        ksort($versions);
        $vendorRoot = joinPaths($root, 'vendor');
        $vendorRealPath = realpath($vendorRoot);
        if ($vendorRealPath === false || !is_dir($vendorRealPath) || is_link($vendorRoot)) {
            releaseValidationFail('MCP offline vendor directory is missing.');
        }
        $installed = readJsonFile(joinPaths($vendorRoot, 'composer/installed.json'), 'installed Composer packages');
        $installedPackages = array_column($installed['packages'] ?? array(), null, 'name');
        $repositoryRoot = $temp->child('offline-mcp-vendor');
        if (!mkdir($repositoryRoot, 0777, true) && !is_dir($repositoryRoot)) {
            releaseValidationFail('Unable to create MCP offline repository.');
        }

        foreach ($versions as $name => $version) {
            $source = joinPaths($vendorRoot, $name);
            $sourceRealPath = realpath($source);
            if (!is_dir($source) || is_link($source) || is_link(dirname($source))
                || $sourceRealPath === false
                || !str_starts_with(normalizePath($sourceRealPath), rtrim(normalizePath($vendorRealPath), '/') . '/')
            ) {
                releaseValidationFail('Locked MCP package is not installed safely: ' . $name);
            }
            if (($installedPackages[$name]['version'] ?? null) !== $version) {
                releaseValidationFail('Installed MCP package version does not match composer.lock: ' . $name);
            }
            $manifest = readJsonFile(joinPaths($source, 'composer.json'), $name . ' installed composer.json');
            if (($manifest['name'] ?? null) !== $name) {
                releaseValidationFail('Installed MCP package name mismatch: ' . $name);
            }
            $this->copyMcpPackageDirectory($source, joinPaths($repositoryRoot, $name));
        }

        return array('root' => $repositoryRoot, 'versions' => $versions);
    }

    private function copyMcpPackageDirectory(string $source, string $target): void
    {
        if (!is_dir($source) || is_link($source)) {
            releaseValidationFail('Locked MCP package directory is missing or a symlink: ' . $source);
        }
        if (!mkdir($target, 0777, true) && !is_dir($target)) {
            releaseValidationFail('Unable to create MCP offline package directory: ' . $target);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                releaseValidationFail('Refusing to copy symlink into MCP offline repository: ' . $entry->getPathname());
            }
            $relative = substr($entry->getPathname(), strlen($source) + 1);
            $destination = joinPaths($target, str_replace(DIRECTORY_SEPARATOR, '/', $relative));
            if ($entry->isDir()) {
                if (!mkdir($destination, 0777, true) && !is_dir($destination)) {
                    releaseValidationFail('Unable to create MCP offline package directory: ' . $destination);
                }
            } elseif (!$entry->isFile() || !copy($entry->getPathname(), $destination)) {
                releaseValidationFail('Unable to copy MCP offline package file: ' . $entry->getPathname());
            }
        }
    }

    /**
     * @param array<string, string> $repositories
     * @return array{root: string, versions: array<string, string>}
     */
    private function prepareMcpFirstPartyOfflineRepository(ReleaseValidationTemporaryDirectory $temp, array $repositories): array
    {
        $temporaryRoot = realpath($temp->path);
        $alphaRoot = $temp->child('alpha-repositories');
        $alphaRealPath = realpath($alphaRoot);
        if ($temporaryRoot === false || $alphaRealPath === false || is_link($alphaRoot)
            || !str_starts_with(normalizePath($alphaRealPath), rtrim(normalizePath($temporaryRoot), '/') . '/')
        ) {
            releaseValidationFail('MCP alpha repository root is unsafe or missing.');
        }

        $queue = array('evolvephp/mcp');
        $versions = array();
        $sources = array();
        while ($queue !== array()) {
            $name = array_shift($queue);
            if (!str_starts_with($name, 'evolvephp/') || isset($versions[$name])) {
                continue;
            }
            if (!preg_match('~^evolvephp/[a-z0-9][a-z0-9._-]*$~', $name)) {
                releaseValidationFail('Unsafe MCP first-party package name: ' . $name);
            }
            if (!isset($repositories[$name])) {
                releaseValidationFail('Missing MCP first-party alpha repository: ' . $name);
            }

            $source = $repositories[$name];
            $sourceRealPath = realpath($source);
            if ($sourceRealPath === false || !is_dir($source) || is_link($source)
                || !str_starts_with(normalizePath($sourceRealPath), rtrim(normalizePath($alphaRealPath), '/') . '/')
            ) {
                releaseValidationFail('Unsafe MCP first-party alpha repository: ' . $name);
            }
            $head = trim($this->runner->mustRun(array('git', '-C', $source, 'rev-parse', 'HEAD'))->stdout);
            $tag = $this->runner->run(array('git', '-C', $source, 'rev-parse', '--verify', 'refs/tags/' . self::ALPHA_VERSION . '^{commit}'));
            if ($tag->exitCode !== 0 || trim($tag->stdout) !== $head) {
                releaseValidationFail('MCP first-party alpha tag does not match HEAD: ' . $name);
            }
            $status = trim($this->runner->mustRun(array('git', '-C', $source, 'status', '--porcelain=v1', '--untracked-files=all'))->stdout);
            if ($status !== '') {
                releaseValidationFail('MCP first-party alpha repository has uncommitted files: ' . $name);
            }

            $manifest = readJsonFile(joinPaths($source, 'composer.json'), $name . ' tagged composer.json');
            if (!is_array($manifest) || ($manifest['name'] ?? null) !== $name) {
                releaseValidationFail('MCP first-party package name mismatch: ' . $name);
            }
            $requires = $manifest['require'] ?? null;
            if (!is_array($requires)) {
                releaseValidationFail('MCP first-party package requirements are invalid: ' . $name);
            }
            foreach (array_keys($requires) as $dependency) {
                if (is_string($dependency) && str_starts_with($dependency, 'evolvephp/')) {
                    $queue[] = $dependency;
                }
            }
            $versions[$name] = self::ALPHA_VERSION;
            $sources[$name] = $source;
        }

        ksort($versions);
        $expected = array('evolvephp/contracts', 'evolvephp/core', 'evolvephp/mcp', 'evolvephp/secret-contracts');
        if (array_keys($versions) !== $expected) {
            releaseValidationFail('MCP first-party alpha dependency closure changed.');
        }

        $repositoryRoot = $temp->child('offline-mcp-first-party');
        if (!mkdir($repositoryRoot, 0777, true) && !is_dir($repositoryRoot)) {
            releaseValidationFail('Unable to create MCP first-party offline repository.');
        }
        foreach ($versions as $name => $version) {
            $target = joinPaths($repositoryRoot, str_replace('/', '-', $name));
            $this->copyTaggedMcpPackageSnapshot($sources[$name], $target);
            $copyManifest = readJsonFile(joinPaths($target, 'composer.json'), $name . ' offline composer.json');
            if (($copyManifest['name'] ?? null) !== $name) {
                releaseValidationFail('Copied MCP first-party package name mismatch: ' . $name);
            }
        }

        return array('root' => $repositoryRoot, 'versions' => $versions);
    }

    private function copyTaggedMcpPackageSnapshot(string $source, string $target): void
    {
        $sourceRealPath = realpath($source);
        if ($sourceRealPath === false || !is_dir($source) || is_link($source)) {
            releaseValidationFail('MCP tagged package source is unsafe: ' . $source);
        }
        $listing = $this->runner->mustRun(array('git', '-C', $source, 'ls-files', '--stage', '-z'))->stdout;
        $entries = explode("\0", rtrim($listing, "\0"));
        if ($entries === array('')) {
            releaseValidationFail('MCP tagged package has no tracked files: ' . $source);
        }
        if (!mkdir($target, 0777, true) && !is_dir($target)) {
            releaseValidationFail('Unable to create MCP first-party package snapshot: ' . $target);
        }

        foreach ($entries as $entry) {
            if (!preg_match('/^(100644|100755) [0-9a-f]{40,64} 0\t(.+)$/s', $entry, $match)) {
                releaseValidationFail('MCP tagged package contains a symlink or unsupported Git entry: ' . $source);
            }
            $relative = $match[2];
            $segments = explode('/', $relative);
            if (str_contains($relative, '\\') || str_contains($relative, ':')
                || array_intersect($segments, array('', '.', '..', '.git')) !== array()
            ) {
                releaseValidationFail('Unsafe MCP tagged package file path: ' . $relative);
            }
            $file = joinPaths($source, $relative);
            $fileRealPath = realpath($file);
            if ($fileRealPath === false || !is_file($file) || is_link($file)
                || !str_starts_with(normalizePath($fileRealPath), rtrim(normalizePath($sourceRealPath), '/') . '/')
            ) {
                releaseValidationFail('MCP tagged package source file is missing or unsafe: ' . $relative);
            }
            $destination = joinPaths($target, $relative);
            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0777, true)) {
                releaseValidationFail('Unable to create MCP tagged package directory: ' . $parent);
            }
            if (!copy($file, $destination)) {
                releaseValidationFail('Unable to copy MCP tagged package file: ' . $relative);
            }
            if ($match[1] === '100755' && !chmod($destination, 0755)) {
                releaseValidationFail('Unable to preserve MCP tagged package executable file: ' . $relative);
            }
        }
    }

    /**
     * @param array<string, string> $repositories
     * @param list<array<string, mixed>> $lockedRuntimePackages
     * @param array<string, string> $requirements
     * @param array<string, string|bool> $consumerOptions
     * @param array{root: string, versions: array<string, string>}|null $offlineRepository
     * @param array{root: string, versions: array<string, string>}|null $firstPartyRepository
     */
    private function createConsumer(ReleaseValidationTemporaryDirectory $temp, string $caseName, array $repositories, array $lockedRuntimePackages, array $requirements, array $consumerOptions, ?array $offlineRepository = null, ?array $firstPartyRepository = null): string
    {
        $directory = $temp->child('consumers/' . strtolower(str_replace(' ', '-', $caseName)));

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            releaseValidationFail('Unable to create consumer fixture for ' . $caseName . '.');
        }

        $repositoryEntries = array();
        if ($offlineRepository !== null) {
            $versions = $offlineRepository['versions'];
            $repositoryEntries[] = array(
                'type' => 'path',
                'url' => normalizePath(joinPaths($offlineRepository['root'], '*/*')),
                'only' => array_keys($versions),
                'canonical' => true,
                'options' => array('symlink' => false, 'versions' => $versions),
            );
            $lockedRuntimePackages = array_values(array_filter(
                $lockedRuntimePackages,
                static fn (array $package): bool => !isset($versions[$package['name']]),
            ));
        }
        if ($firstPartyRepository !== null) {
            $versions = $firstPartyRepository['versions'];
            $repositoryEntries[] = array(
                'type' => 'path',
                'url' => normalizePath(joinPaths($firstPartyRepository['root'], '*')),
                'only' => array_keys($versions),
                'canonical' => true,
                'options' => array('symlink' => false, 'versions' => $versions),
            );
        }
        $repositoryEntries[] = array('packagist.org' => false);
        $repositoryEntries[] = array('type' => 'package', 'package' => $lockedRuntimePackages);

        foreach ($repositories as $repository) {
            $repositoryEntries[] = array('type' => 'vcs', 'url' => normalizePath($repository));
        }

        $manifest = array(
            'name' => 'evolvephp/' . strtolower(str_replace(' ', '-', $caseName)),
            'type' => 'project',
            'repositories' => $repositoryEntries,
            'require' => $requirements,
            'config' => array('allow-plugins' => false, 'secure-http' => false),
        );

        foreach ($consumerOptions as $key => $value) {
            $manifest[$key] = $value;
        }

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded) || file_put_contents(joinPaths($directory, 'composer.json'), $encoded . PHP_EOL) === false) {
            releaseValidationFail('Unable to write disposable consumer fixture for ' . $caseName . '.');
        }

        return $directory;
    }

    private function runComposerUpdate(string $consumer, ReleaseValidationTemporaryDirectory $temp, ?string $composer): ReleaseValidationProcessResult
    {
        return $this->runner->run(
            withComposerCommand(array(
                '--working-dir=' . $consumer,
                'update',
                '--no-interaction',
                '--no-plugins',
                '--no-scripts',
                '--no-audit',
                '--no-install',
                '--no-ansi',
            ), $composer),
            null,
            array(
                'COMPOSER_DISABLE_NETWORK' => '1',
                'COMPOSER_HOME' => $temp->child('composer-home'),
                'COMPOSER_CACHE_DIR' => $temp->child('composer-cache'),
            )
        );
    }

    private function isMinimumStabilityFailure(string $output): bool
    {
        return str_contains($output, 'minimum-stability')
            && str_contains($output, 'found evolvephp/')
            && str_contains($output, 'but it does not match');
    }

    /**
     * @return array<string, string>
     */
    private function readLockedFirstPartyVersions(string $lockfile): array
    {
        $lock = readJsonFile($lockfile, 'disposable composer.lock');

        if (!is_array($lock) || !isset($lock['packages']) || !is_array($lock['packages'])) {
            releaseValidationFail('Disposable composer.lock must contain packages.');
        }

        $versions = array();

        foreach ($lock['packages'] as $package) {
            if (!is_array($package) || !isset($package['name'], $package['version'])) {
                continue;
            }

            if (str_starts_with((string) $package['name'], 'evolvephp/')) {
                $versions[(string) $package['name']] = (string) $package['version'];
            }
        }

        ksort($versions);

        return $versions;
    }

    /**
     * @param list<array{name: string, directory: string}> $packages
     * @return array<string, string>
     */
    private function expectedVersions(array $packages, string $version): array
    {
        $versions = array();

        foreach ($packages as $package) {
            $versions[$package['name']] = $version;
        }

        ksort($versions);

        return $versions;
    }

    /**
     * @param list<array{name: string, directory: string}> $packages
     * @return array<string, string>
     */
    private function explicitAlphaRootRequirements(array $packages): array
    {
        $requirements = array();

        foreach ($packages as $package) {
            $requirements[$package['name']] = '^2.0@alpha';
        }

        return $requirements;
    }

    /**
     * @param array<string, string> $versions
     */
    private function formatVersions(array $versions): string
    {
        $lines = array();

        foreach ($versions as $name => $version) {
            $lines[] = $name . ' ' . $version;
        }

        return implode(', ', $lines);
    }
}

try {
    $options = parseReleaseValidationArguments($argv);
    $validator = new PrereleaseConsumerValidator();
    $results = $validator->validate($options['root'], $options['ref'], $options['composer']);

    echo 'EvolvePHP prerelease consumer validation passed.' . PHP_EOL;
    echo 'COMPOSER_DISABLE_NETWORK: enabled' . PHP_EOL;
    echo 'Packagist: disabled' . PHP_EOL;
    echo 'Alpha tag: 2.0.0-alpha.1' . PHP_EOL;
    echo 'Stable tag: 2.0.0' . PHP_EOL;

    foreach ($results as $result) {
        echo $result['name'] . ': ' . $result['outcome'] . ' - ' . $result['detail'] . PHP_EOL;
    }

    echo '^2.0 remains correct.' . PHP_EOL;
    echo 'Default stability alpha failures are expected minimum-stability rejections.' . PHP_EOL;
    echo 'Top-level @alpha alone is insufficient for transitive alpha packages.' . PHP_EOL;
    echo 'minimum-stability: alpha plus prefer-stable: true succeeds.' . PHP_EOL;
    echo 'Explicit root @alpha flags succeed.' . PHP_EOL;
    echo 'Stable 2.0.0 succeeds under default stability.' . PHP_EOL;
    echo 'No package-manifest change is required.' . PHP_EOL;
    echo 'Source repository state preserved.' . PHP_EOL;
    exit(0);
} catch (ReleaseValidationFailure $failure) {
    fwrite(STDERR, 'EvolvePHP prerelease consumer validation failed: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
