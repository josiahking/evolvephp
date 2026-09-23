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

    public function testSharedProcessRunnerAcceptsDisabledTimeoutForLongRunningSplitOperations(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $runner = new ReleaseValidationProcessRunner(0);
        $result = $runner->run(array(PHP_BINARY, '-r', "fwrite(STDOUT, 'ok');"), null, array(), 'disabled timeout fixture');

        $this->assertSame(0, $result->exitCode);
        $this->assertSame('ok', $result->stdout);
        $this->assertSame('', $result->stderr);
    }

    public function testSharedProcessRunnerRejectsNegativeTimeout(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $this->expectException(ReleaseValidationFailure::class);
        $this->expectExceptionMessage('Process timeout must not be negative.');

        new ReleaseValidationProcessRunner(-1);
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
            '--changed-from=',
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

    public function testSplitValidatorSelectsTargetedPackagesFromChangedPaths(): void
    {
        require_once $this->path('tools/validate-package-splits.php');

        $packages = loadReleasePackages($this->root);

        $this->assertSame(
            array('evolvephp/contracts', 'evolvephp/core', 'evolvephp/insight'),
            array_column(selectPackageSplitPackagesForChangedPaths(
                $packages,
                array(
                    'packages/insight/src/Capture/DeterministicDiagnosticSampler.php',
                    'packages/core/src/ApplicationKernel.php',
                    'packages/contracts/src/ServiceIdentifier.php',
                )
            ), 'name'),
            'Targeted selection must preserve canonical release-package order.'
        );

        $this->assertSame(
            array('evolvephp/core', 'evolvephp/insight'),
            array_column(selectPackageSplitPackagesForChangedPaths(
                $packages,
                array(
                    'packages/core/src/OldLocation.php',
                    'packages/insight/src/NewLocation.php',
                )
            ), 'name'),
            'A cross-package move must select both exposed package roots.'
        );

        $this->assertSame(
            array(),
            selectPackageSplitPackagesForChangedPaths(
                $packages,
                array(
                    'packages/core-extra/README.md',
                    'docs/local-notes.md',
                    'README.md',
                )
            ),
            'Package-directory matching must respect exact directory boundaries and allow zero selected packages.'
        );
    }

    public function testSplitValidatorDiscoversNulDelimitedChangedPathsWithSpecialCharacters(): void
    {
        require_once $this->path('tools/validate-package-splits.php');

        $runner = new ReleaseValidationProcessRunner();
        $temporary = createTemporaryDirectory('evolvephp-special-paths-');
        $fixture = $temporary->path;

        try {
            $this->initializeGitFixture($runner, $fixture);
            $this->writeFixtureFile($fixture, 'README.md', "base\n");

            $runner->mustRun(array('git', 'add', 'README.md'), $fixture);
            $runner->mustRun(array('git', 'commit', '-m', 'Base'), $fixture);
            $base = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $fixture)->stdout);

            $paths = array(
                'packages/insight/src/Capture/brackets [safe] #1.txt',
                'packages/insight/src/Capture/special name.txt',
                'packages/insight/src/Capture/unicode-cafe-' . json_decode('"\u00e9"', true) . '.txt',
            );

            foreach ($paths as $path) {
                $this->writeFixtureFile($fixture, $path, "changed\n");
            }

            $runner->mustRun(array('git', 'add', 'packages/insight/src/Capture'), $fixture);
            $runner->mustRun(array('git', 'commit', '-m', 'Special paths'), $fixture);
            $head = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $fixture)->stdout);
            $before = captureSourceState($runner, $fixture);

            $changedPaths = packageSplitChangedPaths($runner, $fixture, $base, $head);

            $this->assertSame($paths, $changedPaths);
            $this->assertSame($before, captureSourceState($runner, $fixture), 'Changed-path discovery must not mutate HEAD, refs, tags, index or worktree.');
            $this->assertSame(
                array('evolvephp/insight'),
                array_column(selectPackageSplitPackagesForChangedPaths(loadReleasePackages($this->root), $changedPaths), 'name')
            );
        } finally {
            $temporary->cleanup();
        }
    }

    public function testSplitValidatorDiscoversCrossPackageMovesWithoutRenameCollapse(): void
    {
        require_once $this->path('tools/validate-package-splits.php');

        $runner = new ReleaseValidationProcessRunner();
        $temporary = createTemporaryDirectory('evolvephp-move-paths-');
        $fixture = $temporary->path;

        try {
            $this->initializeGitFixture($runner, $fixture);
            $this->writeFixtureFile($fixture, 'packages/core/src/Moved.php', "<?php\n");

            $runner->mustRun(array('git', 'add', 'packages/core/src/Moved.php'), $fixture);
            $runner->mustRun(array('git', 'commit', '-m', 'Base'), $fixture);
            $base = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $fixture)->stdout);

            $this->writeFixtureFile($fixture, 'packages/insight/src/Moved.php', "<?php\n");
            $runner->mustRun(array('git', 'rm', 'packages/core/src/Moved.php'), $fixture);
            $runner->mustRun(array('git', 'add', 'packages/insight/src/Moved.php'), $fixture);
            $runner->mustRun(array('git', 'commit', '-m', 'Move across packages'), $fixture);
            $head = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $fixture)->stdout);

            $changedPaths = packageSplitChangedPaths($runner, $fixture, $base, $head);

            $this->assertSame(
                array('packages/core/src/Moved.php', 'packages/insight/src/Moved.php'),
                $changedPaths
            );
            $this->assertSame(
                array('evolvephp/core', 'evolvephp/insight'),
                array_column(selectPackageSplitPackagesForChangedPaths(loadReleasePackages($this->root), $changedPaths), 'name')
            );
        } finally {
            $temporary->cleanup();
        }
    }

    public function testSplitValidatorParsesNulDelimitedPathOutputWithoutTrimmingBytes(): void
    {
        require_once $this->path('tools/validate-package-splits.php');

        $paths = parsePackageSplitChangedPathOutput(" leading.txt\0trailing.txt \0tab\tpath.txt\0\0");

        $this->assertSame(array(' leading.txt', 'trailing.txt ', "tab\tpath.txt", ''), $paths);
    }

    public function testSplitValidatorFailsSafeToFullValidationForReleaseSensitivePaths(): void
    {
        require_once $this->path('tools/validate-package-splits.php');

        foreach (array(
            'release-packages.json',
            'tools/validate-package-splits.php',
            'tools/release-validation-common.php',
            'composer.json',
            '.github/workflows/quality.yml',
            'packages/core/composer.json',
            'deptrac.php',
            'phpstan.neon.dist',
            'phpunit.xml.dist',
            'tools/local-helper.php',
            'packages/core-extra/README.md',
            'config/release-helper.php',
        ) as $path) {
            $decision = decidePackageSplitValidationScope(loadReleasePackages($this->root), array($path));

            $this->assertSame('full', $decision['mode'], $path . ' must force full validation.');
            $this->assertSame(13, count($decision['packages']), $path . ' must keep the complete package map.');
        }

        $decision = decidePackageSplitValidationScope(loadReleasePackages($this->root), array('docs/release-notes.md', 'README.md'));

        $this->assertSame('targeted', $decision['mode']);
        $this->assertSame(array(), $decision['packages']);
    }

    public function testSplitValidatorClassifiesCommittedDocumentationChangesConservatively(): void
    {
        require_once $this->path('tools/validate-package-splits.php');

        $packages = loadReleasePackages($this->root);

        $documentationOnly = $this->classifyCommittedFixturePaths(array(
            'docs/release-notes.md' => "notes\n",
            'docs/guides/local-validation.md' => "guide\n",
        ));

        $this->assertSame('targeted', $documentationOnly['mode']);
        $this->assertSame(array(), $documentationOnly['packages']);

        foreach (array(
            array(
                'paths' => array('docs/release-helper.php' => "<?php\n"),
                'label' => 'docs/release-helper.php',
            ),
            array(
                'paths' => array('docs/local-validation.neon' => "parameters:\n"),
                'label' => 'docs/local-validation.neon',
            ),
            array(
                'paths' => array(
                    'docs/release-notes.md' => "notes\n",
                    'docs/release-helper.php' => "<?php\n",
                ),
                'label' => 'mixed safe documentation and helper',
            ),
        ) as $case) {
            $decision = $this->classifyCommittedFixturePaths($case['paths']);

            $this->assertSame('full', $decision['mode'], $case['label'] . ' must force full validation.');
            $this->assertSame($packages, $decision['packages'], $case['label'] . ' must preserve the complete canonical package map.');
        }
    }

    public function testSplitValidatorValidatesChangedFromRefsAndRejectsBadComparisons(): void
    {
        require_once $this->path('tools/validate-package-splits.php');

        $runner = new ReleaseValidationProcessRunner();
        $temporary = createTemporaryDirectory('evolvephp-changed-from-contract-test-');

        try {
            $runner->mustRun(array('git', 'init'), $temporary->path);
            $runner->mustRun(array('git', 'config', 'user.name', 'EvolvePHP Test'), $temporary->path);
            $runner->mustRun(array('git', 'config', 'user.email', 'evolvephp-test@example.com'), $temporary->path);

            file_put_contents($temporary->child('README.md'), "base\n");
            $runner->mustRun(array('git', 'add', 'README.md'), $temporary->path);
            $runner->mustRun(array('git', 'commit', '-m', 'Base'), $temporary->path);
            $base = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $temporary->path)->stdout);

            file_put_contents($temporary->child('README.md'), "main\n");
            $runner->mustRun(array('git', 'commit', '-am', 'Main'), $temporary->path);
            $head = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $temporary->path)->stdout);

            $runner->mustRun(array('git', 'checkout', '-b', 'side', $base), $temporary->path);
            file_put_contents($temporary->child('README.md'), "side\n");
            $runner->mustRun(array('git', 'commit', '-am', 'Side'), $temporary->path);
            $side = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $temporary->path)->stdout);
            $runner->mustRun(array('git', 'checkout', '--detach', $head), $temporary->path);

            $this->assertSame($base, resolvePackageSplitChangedFrom($runner, $temporary->path, $base, $head));

            foreach (array(
                array('', '--changed-from must not be empty.'),
                array('missing-ref', '--changed-from must resolve to a Git commit.'),
                array($side, '--changed-from must be an ancestor of --ref.'),
            ) as $case) {
                try {
                    resolvePackageSplitChangedFrom($runner, $temporary->path, $case[0], $head);
                    $this->fail($case[0] . ' should be rejected.');
                } catch (ReleaseValidationFailure $failure) {
                    $this->assertStringContainsString($case[1], $failure->getMessage());
                }
            }
        } finally {
            $temporary->cleanup();
        }
    }

    public function testChangedFromArgumentIsOnlyAcceptedBySplitValidatorContract(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $splitOptions = parseReleaseValidationArguments(
            array('validate-package-splits.php', '--root=' . $this->root, '--ref=HEAD', '--composer=composer', '--changed-from=HEAD~1'),
            true,
            array('changed-from')
        );

        $this->assertSame('HEAD~1', $splitOptions['changed-from']);

        foreach (array(
            array('validate-prerelease-consumers.php', true),
            array('validate-skeleton-project.php', false),
        ) as $case) {
            try {
                parseReleaseValidationArguments(array($case[0], '--changed-from=HEAD~1'), $case[1]);
                $this->fail($case[0] . ' should not accept --changed-from.');
            } catch (ReleaseValidationFailure $failure) {
                $this->assertStringContainsString('Unknown CLI option: --changed-from=HEAD~1', $failure->getMessage());
            }
        }
    }

    public function testTargetedSplitModeUsesCommittedPathsNoRenamesAndExistingValidationLoop(): void
    {
        $content = $this->readProjectFile('tools/validate-package-splits.php');

        $this->assertStringContainsString("'diff'", $content);
        $this->assertStringContainsString("'--name-only'", $content);
        $this->assertStringContainsString("'--no-renames'", $content);
        $this->assertStringContainsString("'-z'", $content);
        $this->assertMatchesRegularExpression('/foreach\s*\(\s*\$selectedPackages\s+as\s+\$package\s*\).*?\$this->validatePackage/s', $content);
        $this->assertStringContainsString('assertSourceStatePreserved($this->runner, $root, $sourceState);', $content);
    }

    public function testOnlySplitValidatorDisablesInternalProcessRunnerTimeout(): void
    {
        $splitContent = $this->readProjectFile('tools/validate-package-splits.php');
        $consumerContent = $this->readProjectFile('tools/validate-prerelease-consumers.php');
        $skeletonContent = $this->readProjectFile('tools/validate-skeleton-project.php');

        $this->assertStringContainsString('new ReleaseValidationProcessRunner(0)', $splitContent);
        $this->assertDoesNotMatchRegularExpression('/new\s+ReleaseValidationProcessRunner\s*\(\s*0\s*\)/', $consumerContent);
        $this->assertDoesNotMatchRegularExpression('/new\s+ReleaseValidationProcessRunner\s*\(\s*0\s*\)/', $skeletonContent);
        $this->assertStringContainsString('new ReleaseValidationProcessRunner()', $consumerContent);
        $this->assertStringContainsString('new ReleaseValidationProcessRunner()', $skeletonContent);
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

    public function testConsumerValidatorClonesDisposableTaggedRepositoriesWithoutInheritedTags(): void
    {
        $content = $this->readProjectFile('tools/validate-prerelease-consumers.php');

        $this->assertSame(
            1,
            preg_match(
                '/private function createTaggedRepositories.*?git\', \'clone\', \'--no-hardlinks\', \'--no-tags\', \$splitRoot, \$repository.*?git\', \'-C\', \$repository, \'tag\', \$tag/s',
                $content
            ),
            'Disposable tagged repositories must clone split roots without source tags before creating synthetic fixture tags.'
        );
        $this->assertDoesNotMatchRegularExpression('/\\b(?:git push|remote add|config --global)\\b/i', $content);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:shell_exec|exec|passthru|system)\\s*\\(/', $content);
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
        $devPackages = $this->lockedPackagesByName($lock['packages-dev']);
        $lockedPackages = array_merge($runtimePackages, $devPackages);
        $fixtureNames = array_column($fixtures, 'name');
        $sortedFixtureNames = $fixtureNames;

        sort($sortedFixtureNames);

        $this->assertSame($sortedFixtureNames, $fixtureNames, 'Offline runtime package metadata must be sorted deterministically.');
        $this->assertArrayHasKey('psr/http-client', $devPackages, 'The PSR-18 interface package exercises runtime closure metadata resolved from packages-dev.');
        $this->assertContains('psr/http-client', $fixtureNames, 'PSR-18 must be available to offline consumers because bridge-remote requires it at runtime.');

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
            $this->assertArrayHasKey($fixture['name'], $lockedPackages, $fixture['name'] . ' must come from composer.lock packages or packages-dev.');
            $this->assertSame($lockedPackages[$fixture['name']]['version'], $fixture['version'], $fixture['name'] . ' must keep the locked version.');
            $this->assertTrue(
                isset($fixture['source']) || isset($fixture['dist']),
                $fixture['name'] . ' must contain source or dist metadata for the offline package repository.'
            );
        }

        foreach (array('deptrac/deptrac', 'friendsofphp/php-cs-fixer', 'phpstan/phpstan') as $packageName) {
            $this->assertNotContains($packageName, $fixtureNames, $packageName . ' must not be exposed unless it is in a release-package runtime dependency closure.');
        }

        $content = $this->readProjectFile('tools/validate-prerelease-consumers.php');

        $this->assertStringContainsString("'type' => 'package'", $content);
        $this->assertStringContainsString("'package' => \$lockedRuntimePackages", $content);
        $this->assertStringContainsString("'packagist.org' => false", $content);
        $this->assertStringContainsString("'COMPOSER_DISABLE_NETWORK' => '1'", $content);
    }

    public function testSharedReleasePackageLoaderAcceptsCanonicalThirteenPackageMap(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $packages = loadReleasePackages($this->root);
        $map = $this->readJsonFile('release-packages.json');
        $packageNames = array_column($packages, 'name');
        $coreIndex = array_search('evolvephp/core', $packageNames, true);

        $this->assertIsInt($coreIndex);
        $this->assertCount(13, $packages);
        $this->assertContains('evolvephp/insight', $packageNames);
        $this->assertSame(
            array('name' => 'evolvephp/insight', 'directory' => 'packages/insight'),
            $packages[$coreIndex + 1]
        );
        $this->assertSame($map['packages'], $packages);
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
        $this->assertSame(1, substr_count($workflow, 'Run prerelease consumer validation'));
        $this->assertSame(1, substr_count($workflow, 'Run application skeleton create-project validation'));
        $this->assertSame(1, substr_count($workflow, 'composer release:split:validate'));
        $this->assertSame(1, substr_count($workflow, 'composer release:consumer:validate'));
        $this->assertSame(1, substr_count($workflow, 'composer release:skeleton:validate'));
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

    private function initializeGitFixture($runner, $path)
    {
        $runner->mustRun(array('git', 'init'), $path);
        $runner->mustRun(array('git', 'config', 'user.name', 'EvolvePHP Test'), $path);
        $runner->mustRun(array('git', 'config', 'user.email', 'evolvephp-test@example.com'), $path);
        $runner->mustRun(array('git', 'config', 'core.quotepath', 'false'), $path);
    }

    private function writeFixtureFile($root, $relativePath, $contents)
    {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            $this->assertTrue(mkdir($directory, 0777, true), 'Fixture directory must be created: ' . $relativePath);
        }

        $this->assertIsInt(file_put_contents($path, $contents), 'Fixture file must be writable: ' . $relativePath);
    }

    private function classifyCommittedFixturePaths($paths)
    {
        $runner = new ReleaseValidationProcessRunner();
        $temporary = createTemporaryDirectory('evolvephp-doc-classification-');
        $fixture = $temporary->path;

        try {
            $this->initializeGitFixture($runner, $fixture);
            $this->writeFixtureFile($fixture, 'README.md', "base\n");

            $runner->mustRun(array('git', 'add', 'README.md'), $fixture);
            $runner->mustRun(array('git', 'commit', '-m', 'Base'), $fixture);
            $base = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $fixture)->stdout);

            foreach ($paths as $path => $contents) {
                $this->writeFixtureFile($fixture, $path, $contents);
            }

            $runner->mustRun(array('git', 'add', '.'), $fixture);
            $runner->mustRun(array('git', 'commit', '-m', 'Changed paths'), $fixture);
            $head = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $fixture)->stdout);
            $changedPaths = packageSplitChangedPaths($runner, $fixture, $base, $head);

            return decidePackageSplitValidationScope(loadReleasePackages($this->root), $changedPaths);
        } finally {
            $temporary->cleanup();
        }
    }

    private function releasePackages()
    {
        $map = $this->readJsonFile('release-packages.json');

        $this->assertSame(1, $map['version']);
        $this->assertCount(13, $map['packages']);

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
