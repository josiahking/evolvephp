<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2ReleaseSplitAndConsumerValidationTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testReleaseSplitConsumerAndSkeletonValidationToolsAreRepositoryOwnedPhpEntrypoints(): void
    {
        $this->assertFileExists($this->path('tools/release-validation-common.php'));

        foreach (array(
            'tools/validate-package-splits.php',
            'tools/validate-prerelease-consumers.php',
            'tools/validate-skeleton-project.php',
        ) as $path) {
            $content = $this->readProjectFile($path);

            $this->assertStringStartsWith('<?php declare(strict_types=1);', $content);
            $this->assertStringContainsString("require_once __DIR__ . '/release-validation-common.php';", $content);
            $this->assertStringNotContainsString('D:\\tools\\composer84\\composer.phar', $content);
            $this->assertDoesNotMatchRegularExpression('/\\b(?:curl|gh|git push|remote add)\\b/i', $content);
        }
    }

    public function testSharedHelperUsesArgumentVectorProcessesAndDoesNotRunOnInclude(): void
    {
        $content = $this->readProjectFile('tools/release-validation-common.php');

        $this->assertStringContainsString('proc_open(', $content);
        $this->assertStringContainsString('bypass_shell', $content);
        $this->assertStringContainsString('loadReleasePackages', $content);
        $this->assertStringContainsString('loadLockedPackageRepositoryPackages', $content);
        $this->assertStringContainsString('createTemporaryDirectory', $content);
        $this->assertStringContainsString('removeDirectory', $content);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:shell_exec|exec|passthru|system)\\s*\\(/', $content);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:git push|gh|curl|remote add|config --global)\\b/i', $content);
        $this->assertDoesNotMatchRegularExpression('/EvolvePHP .* validation passed/', $content);
    }

    public function testSharedProcessRunnerBoundsProcessesAndReportsStage(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $runner = new ReleaseValidationProcessRunner(1);
        $startedAt = microtime(true);

        try {
            $runner->run(
                array(PHP_BINARY, '-r', "fwrite(STDOUT, 'started'); sleep(3);"),
                null,
                array(),
                'slow fixture'
            );

            $this->fail('Slow fixture should time out.');
        } catch (ReleaseValidationFailure $failure) {
            $elapsed = microtime(true) - $startedAt;

            $this->assertLessThan(3.0, $elapsed, 'The timeout fixture must not wait for the full sleep duration.');
            $this->assertStringContainsString('Process timed out after 1 second during slow fixture', $failure->getMessage());
            $this->assertStringContainsString(PHP_BINARY, $failure->getMessage());
        }
    }

    public function testSharedProcessRunnerAllowsShortProcessesWithTimeout(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $runner = new ReleaseValidationProcessRunner(5);
        $result = $runner->run(array(PHP_BINARY, '-r', "fwrite(STDOUT, 'ok');"), null, array(), 'short fixture');

        $this->assertSame(0, $result->exitCode);
        $this->assertSame('ok', $result->stdout);
        $this->assertSame('', $result->stderr);
    }

    public function testTemporaryDirectoryCleanupRemovesValidatorOwnedPath(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $temporary = createTemporaryDirectory('evolvephp-cleanup-contract-test-');
        $path = $temporary->path;

        file_put_contents($temporary->child('marker.txt'), 'marker');

        $temporary->cleanup();

        $this->assertDirectoryDoesNotExist($path);
    }

    public function testSourceStateCaptureUsesDeterministicRefEnumerationForDetachedCi(): void
    {
        $content = $this->readProjectFile('tools/release-validation-common.php');

        $this->assertStringContainsString("'for-each-ref'", $content);
        $this->assertStringContainsString("'--sort=refname'", $content);
        $this->assertStringContainsString("'--format=%(objectname) %(refname)'", $content);
        $this->assertStringContainsString("'refs/heads'", $content);
        $this->assertStringContainsString("'refs/tags'", $content);
        $this->assertStringNotContainsString("'show-ref', '--heads', '--tags'", $content);
        $this->assertStringNotContainsString('|| true', $content);
        $this->assertStringNotContainsString('2>/dev/null', $content);
    }

    public function testSourceStateCaptureSupportsDetachedRepositoriesWithoutLocalHeadsOrTags(): void
    {
        $helperPath = $this->path('tools/release-validation-common.php');

        $this->assertFileExists($helperPath, 'tools/release-validation-common.php must exist before detached source-state behaviour is tested.');

        require_once $helperPath;

        $runner = new ReleaseValidationProcessRunner();
        $temporary = createTemporaryDirectory('evolvephp-detached-source-state-test-');

        try {
            $runner->mustRun(array('git', 'init'), $temporary->path);
            $runner->mustRun(array('git', 'config', 'user.name', 'EvolvePHP Test'), $temporary->path);
            $runner->mustRun(array('git', 'config', 'user.email', 'evolvephp-test@example.com'), $temporary->path);

            file_put_contents($temporary->child('README.md'), "detached fixture\n");

            $runner->mustRun(array('git', 'add', 'README.md'), $temporary->path);
            $runner->mustRun(array('git', 'commit', '-m', 'Initial fixture'), $temporary->path);

            $branch = trim($runner->mustRun(array('git', 'symbolic-ref', '--short', 'HEAD'), $temporary->path)->stdout);
            $head = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $temporary->path)->stdout);

            $runner->mustRun(array('git', 'checkout', '--detach', $head), $temporary->path);
            $runner->mustRun(array('git', 'branch', '-D', $branch), $temporary->path);

            $state = captureSourceState($runner, $temporary->path);

            $this->assertSame($head, $state['head']);
            $this->assertSame('', $state['refs']);
            $this->assertSame('', $state['tags']);
        } finally {
            $temporary->cleanup();
        }
    }

    public function testSplitValidatorDocumentsDeterministicSplitContract(): void
    {
        $content = $this->readProjectFile('tools/validate-package-splits.php');

        foreach (array(
            '--root=',
            '--ref=',
            '--composer=',
            'git subtree split',
            'first split',
            'second split',
            'deterministic: yes',
            'tree equality: yes',
            'inventory equality: yes',
            'composer validate --strict: pass',
            'history commits:',
            'Source repository state preserved.',
        ) as $needle) {
            $this->assertStringContainsString($needle, $content);
        }

        $this->assertStringNotContainsString('2.0.0-alpha.1', $content);
        $this->assertStringNotContainsString('git tag', $content);
    }

    public function testConsumerValidatorDocumentsOfflinePrereleaseAndStableMatrix(): void
    {
        $content = $this->readProjectFile('tools/validate-prerelease-consumers.php');

        foreach (array(
            'COMPOSER_DISABLE_NETWORK',
            'packagist.org',
            '2.0.0-alpha.1',
            '2.0.0',
            'Alpha case A',
            'Alpha case B',
            'Alpha case C',
            'Alpha case D',
            'Full-graph case E',
            'Full-graph case F',
            'Full-graph case G',
            'Stable case H',
            'expected failure',
            'minimum-stability',
            'prefer-stable',
            'Source repository state preserved.',
        ) as $needle) {
            $this->assertStringContainsString($needle, $content);
        }

        $this->assertDoesNotMatchRegularExpression('/\\b(?:curl|gh|git push|remote add|config --global)\\b/i', $content);
    }

    public function testConsumerValidatorUsesLockedRuntimePackagesForOfflineThirdPartyResolution(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $this->assertTrue(
            function_exists('loadLockedRuntimePackageRepositoryPackages'),
            'release-validation-common.php must expose lockfile-derived runtime package repository metadata.'
        );

        $fixtures = loadLockedRuntimePackageRepositoryPackages($this->root);
        $lock = $this->readJsonFile('composer.lock');
        $runtimePackages = $this->lockedPackagesByName($lock['packages']);
        $devPackageNames = array_keys($this->lockedPackagesByName($lock['packages-dev']));
        $runtimePackageNames = array_keys($runtimePackages);
        $devOnlyPackageNames = array_diff($devPackageNames, $runtimePackageNames);
        $fixtureNames = array_column($fixtures, 'name');
        $sortedFixtureNames = $fixtureNames;

        sort($sortedFixtureNames);

        $this->assertSame($sortedFixtureNames, $fixtureNames, 'Offline runtime package metadata must be sorted deterministically.');

        foreach ($this->directExternalRuntimeRequirements() as $packageName) {
            $this->assertContains($packageName, $fixtureNames, $packageName . ' must be available to offline consumers.');
        }

        foreach ($fixtures as $fixture) {
            $this->assertIsString($fixture['name']);
            $this->assertNotSame('', $fixture['name']);
            $this->assertIsString($fixture['version']);
            $this->assertNotSame('', $fixture['version']);
            $this->assertFalse(str_starts_with($fixture['name'], 'evolvephp/'), $fixture['name'] . ' must not be supplied from lockfile metadata.');
            $this->assertFalse($this->isPlatformRequirement($fixture['name']), $fixture['name'] . ' must not be supplied as a package repository entry.');
            $this->assertArrayHasKey($fixture['name'], $runtimePackages, $fixture['name'] . ' must come from composer.lock packages.');
            $this->assertSame($runtimePackages[$fixture['name']]['version'], $fixture['version'], $fixture['name'] . ' must keep the locked runtime version.');
            $this->assertTrue(
                isset($fixture['source']) || isset($fixture['dist']),
                $fixture['name'] . ' must contain source or dist metadata for the offline package repository.'
            );
        }

        foreach ($devOnlyPackageNames as $packageName) {
            $this->assertNotContains($packageName, $fixtureNames, $packageName . ' must not come from composer.lock packages-dev.');
        }

        $content = $this->readProjectFile('tools/validate-prerelease-consumers.php');

        $this->assertStringContainsString("'type' => 'package'", $content);
        $this->assertStringContainsString("'package' => \$lockedRuntimePackages", $content);
        $this->assertStringContainsString("'packagist.org' => false", $content);
        $this->assertStringContainsString("'COMPOSER_DISABLE_NETWORK' => '1'", $content);
    }

    public function testSkeletonValidatorUsesMinimalLockedOfflineVendorClosure(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $this->assertTrue(
            function_exists('loadSkeletonLockedPackageRepositoryPackages'),
            'release-validation-common.php must expose skeleton-specific lockfile package metadata.'
        );

        $fixtures = loadSkeletonLockedPackageRepositoryPackages($this->root);
        $fixtureNames = array_column($fixtures, 'name');
        $sortedFixtureNames = $fixtureNames;

        sort($sortedFixtureNames);

        $this->assertSame($sortedFixtureNames, $fixtureNames, 'Skeleton offline package metadata must be sorted deterministically.');

        foreach (array('phpunit/phpunit', 'psr/container', 'psr/http-message') as $packageName) {
            $this->assertContains($packageName, $fixtureNames, $packageName . ' must be available to offline skeleton validation.');
        }

        foreach (array('deptrac/deptrac', 'friendsofphp/php-cs-fixer', 'phpstan/phpstan') as $packageName) {
            $this->assertNotContains($packageName, $fixtureNames, $packageName . ' must not be copied into the skeleton validator repository.');
        }

        $this->assertLessThan(
            count($this->lockedPackagesByName($this->readJsonFile('composer.lock')['packages-dev'])),
            count($fixtureNames),
            'Skeleton validation should not expose every dev package from the root vendor directory.'
        );
    }

    public function testSkeletonValidatorDocumentsRealOfflineCreateProjectContract(): void
    {
        $content = $this->readProjectFile('tools/validate-skeleton-project.php');

        foreach (array(
            'captureSourceState',
            'assertSourceStatePreserved',
            'createTemporaryDirectory',
            'loadSkeletonLockedPackageRepositoryPackages',
            'create-project',
            'COMPOSER_DISABLE_NETWORK',
            'packagist.org',
            'symlink',
            'composer validate --strict',
            'bin/evolve',
            'doctor',
            'route:list',
            'No routes are configured.',
            'No command was specified.',
            'Command "missing" was not found.',
            'The route:list command does not accept arguments or options.',
            'module:new',
            'plugin:new',
            'composer install --no-dev',
            'Source repository state preserved.',
        ) as $needle) {
            $this->assertStringContainsString($needle, $content);
        }

        foreach (array(
            '[1/13] Preparing offline repositories',
            '[2/13] Running Composer create-project',
            '[3/13] Validating generated manifest',
            '[4/13] Validating installed packages',
            '[5/13] Running generated Doctor',
            '[6/13] Running generated route:list',
            '[7/13] Running generated module:new',
            '[8/13] Running generated plugin:new',
            '[9/13] Running generated test suite',
            '[10/13] Running collision and traversal checks',
            '[11/13] Running Composer install --no-dev',
            '[12/13] Running no-dev Doctor and route:list',
            '[13/13] Cleaning up and preserving source state',
        ) as $stage) {
            $this->assertStringContainsString($stage, $content);
        }

        $this->assertStringContainsString('loadSkeletonLockedPackageRepositoryPackages', $content);
        $this->assertStringContainsString('prepareOfflineVendorRepository', $content);
        $this->assertStringNotContainsString('joinPaths($root, \'vendor/*/*\')', $content);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:curl|gh|git push|remote add|config --global|shell_exec|exec|passthru|system)\\b/i', $content);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:robocopy|xcopy)\\b/i', $content);
    }

    public function testWorkspaceComposerExposesReleaseValidationScriptsWithoutPrepareScript(): void
    {
        $manifest = $this->readJsonFile('composer.json');

        $this->assertSame(array('@architecture', '@analyse', '@style:check', '@test'), $manifest['scripts']['quality']);
        $this->assertSame(array('@security:audit', '@licenses:check'), $manifest['scripts']['supply-chain']);
        $this->assertSame('@php tools/validate-release-packages.php', $manifest['scripts']['release:validate']);
        $this->assertSame('@php tools/validate-package-splits.php', $manifest['scripts']['release:split:validate']);
        $this->assertSame('@php tools/validate-prerelease-consumers.php', $manifest['scripts']['release:consumer:validate']);
        $this->assertSame('@php tools/validate-skeleton-project.php', $manifest['scripts']['release:skeleton:validate']);
        $this->assertArrayNotHasKey('release:prepare', $manifest['scripts']);
    }

    public function testPackageManifestsRetainStableInternalConstraintsAndNoStabilityPolicy(): void
    {
        foreach ($this->releasePackages() as $package) {
            $manifest = $this->readJsonFile($package['directory'] . '/composer.json');

            $this->assertArrayNotHasKey('version', $manifest, $package['name']);
            $this->assertArrayNotHasKey('minimum-stability', $manifest, $package['name']);
            $this->assertArrayNotHasKey('prefer-stable', $manifest, $package['name']);
            $this->assertSame('^8.4', $manifest['require']['php'], $package['name']);

            foreach ($manifest['require'] as $dependency => $constraint) {
                if (strpos($dependency, 'evolvephp/') !== 0) {
                    continue;
                }

                $this->assertSame('^2.0', $constraint, $package['name'] . ' internal constraint for ' . $dependency);
                $this->assertStringNotContainsString('@alpha', $constraint, $package['name']);
            }
        }
    }

    public function testCiRunsPackageSplitAndSkeletonValidationInExistingPolicyJob(): void
    {
        $workflow = $this->readProjectFile('.github/workflows/quality.yml');

        $this->assertSame(1, substr_count($workflow, 'name: Policy (PHP 8.4)'));
        $this->assertSame(1, substr_count($workflow, 'name: Workspace quality (PHP ${{ matrix.php }})'));
        $this->assertSame(1, substr_count($workflow, 'Run release package split validation'));
        $this->assertSame(1, substr_count($workflow, 'Run application skeleton create-project validation'));
        $this->assertStringContainsString('composer release:split:validate', $workflow);
        $this->assertStringContainsString('composer release:skeleton:validate', $workflow);
        $this->assertStringNotContainsString('release:consumer:validate', $workflow);
        $this->assertStringContainsString('Run root supply-chain checks', $workflow);
        $this->assertStringContainsString('Run root policy tests', $workflow);
    }

    public function testWorkspaceReadmeDocumentsAlphaConsumerPolicyAndDeferredPublication(): void
    {
        $content = $this->readProjectFile('DEVELOPMENT.md');

        foreach (array(
            'composer release:split:validate',
            'composer release:consumer:validate',
            'composer release:skeleton:validate',
            'skeleton/',
            'evolvephp/skeleton',
            'public experimental',
            'CliApplication',
            'StreamCommandOutput',
            'application CLI composition is explicit',
            'Core remains independent of HTTP',
            'Packagist create-project availability is not yet claimed',
            'minimum-stability: alpha',
            'prefer-stable: true',
            'Explicit root `@alpha` flags',
            'no package Composer manifest should add `@alpha`, `minimum-stability`, `prefer-stable` or a hard-coded `version` field.',
            'Remote package repositories, remote synchronization, Packagist registration, tags and releases remain deferred.',
        ) as $needle) {
            $this->assertStringContainsString($needle, $content);
        }
    }

    public function testChangelogRecordsPhase210BValidationWithoutPublicationClaims(): void
    {
        $content = $this->readProjectFile('CHANGELOG.md');

        $this->assertStringContainsString('Phase 2.10B deterministic package split and prerelease consumer validation', $content);
        $this->assertStringContainsString('alpha consumer policy', $content);
        $this->assertDoesNotMatchRegularExpression('/Phase 2\\.10B.*(?:published|Packagist|GitHub release)/i', $content);
    }

    private function readProjectFile($path)
    {
        $absolute = $this->path($path);

        $this->assertFileExists($absolute, $path . ' must exist.');

        $content = file_get_contents($absolute);

        $this->assertIsString($content, $path . ' must be readable.');

        return $content;
    }

    private function readJsonFile($path)
    {
        $decoded = json_decode($this->readProjectFile($path), true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), $path . ' must contain valid JSON.');
        $this->assertIsArray($decoded, $path . ' must decode to an array.');

        return $decoded;
    }

    private function releasePackages()
    {
        $map = $this->readJsonFile('release-packages.json');

        $this->assertSame(1, $map['version']);
        $this->assertCount(7, $map['packages']);

        return $map['packages'];
    }

    private function directExternalRuntimeRequirements()
    {
        $requirements = array();

        foreach ($this->releasePackages() as $package) {
            $manifest = $this->readJsonFile($package['directory'] . '/composer.json');

            foreach ($manifest['require'] as $packageName => $constraint) {
                if (str_starts_with($packageName, 'evolvephp/') || $this->isPlatformRequirement($packageName)) {
                    continue;
                }

                $requirements[$packageName] = true;
            }
        }

        $names = array_keys($requirements);
        sort($names);

        return $names;
    }

    private function lockedPackagesByName($packages)
    {
        $this->assertIsArray($packages);

        $locked = array();

        foreach ($packages as $package) {
            $this->assertIsArray($package);
            $this->assertArrayHasKey('name', $package);
            $this->assertArrayHasKey('version', $package);

            $locked[$package['name']] = $package;
        }

        ksort($locked);

        return $locked;
    }

    private function isPlatformRequirement($packageName)
    {
        return $packageName === 'php'
            || str_starts_with($packageName, 'ext-')
            || str_starts_with($packageName, 'lib-');
    }

    private function path($path)
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
