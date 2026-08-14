<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2ReleaseSplitAndConsumerValidationTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testReleaseSplitAndConsumerValidationToolsAreRepositoryOwnedPhpEntrypoints(): void
    {
        $this->assertFileExists($this->path('tools/release-validation-common.php'));

        foreach (array(
            'tools/validate-package-splits.php',
            'tools/validate-prerelease-consumers.php',
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
        $this->assertStringContainsString('createTemporaryDirectory', $content);
        $this->assertStringContainsString('removeDirectory', $content);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:shell_exec|exec|passthru|system)\\s*\\(/', $content);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:git push|gh|curl|remote add|config --global)\\b/i', $content);
        $this->assertDoesNotMatchRegularExpression('/EvolvePHP .* validation passed/', $content);
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

    public function testWorkspaceComposerExposesReleaseValidationScriptsWithoutPrepareScript(): void
    {
        $manifest = $this->readJsonFile('composer.json');

        $this->assertSame(array('@architecture', '@analyse', '@style:check', '@test'), $manifest['scripts']['quality']);
        $this->assertSame(array('@security:audit', '@licenses:check'), $manifest['scripts']['supply-chain']);
        $this->assertSame('@php tools/validate-release-packages.php', $manifest['scripts']['release:validate']);
        $this->assertSame('@php tools/validate-package-splits.php', $manifest['scripts']['release:split:validate']);
        $this->assertSame('@php tools/validate-prerelease-consumers.php', $manifest['scripts']['release:consumer:validate']);
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

    public function testCiRunsOnlyPackageSplitValidationInExistingPolicyJob(): void
    {
        $workflow = $this->readProjectFile('.github/workflows/quality.yml');

        $this->assertSame(1, substr_count($workflow, 'name: Policy (PHP 8.4)'));
        $this->assertSame(1, substr_count($workflow, 'name: Workspace quality (PHP ${{ matrix.php }})'));
        $this->assertSame(1, substr_count($workflow, 'Run release package split validation'));
        $this->assertStringContainsString('composer release:split:validate', $workflow);
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
        $this->assertCount(6, $map['packages']);

        return $map['packages'];
    }

    private function path($path)
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
