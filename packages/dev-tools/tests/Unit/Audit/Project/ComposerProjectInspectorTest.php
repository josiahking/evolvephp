<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Audit\Project;

use Evolve\DevTools\Audit\AuditFinding;
use Evolve\DevTools\Audit\AuditSeverity;
use Evolve\DevTools\Audit\Project\ComposerProjectInspector;
use PHPUnit\Framework\TestCase;

final class ComposerProjectInspectorTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = $this->createProjectRoot();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testValidRootManifestReportsDirectEvidence(): void
    {
        $this->writeComposerJson([
            'require' => [
                'vendor/zeta' => '^2.0',
                'php' => '^8.1 || ^8.2',
                'vendor/alpha' => '^1.0',
            ],
            'require-dev' => [
                'phpunit/phpunit' => '^13.0',
                'mockery/mockery' => '^2.0',
            ],
        ]);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'composer_json.present', AuditSeverity::Info, [
            'path' => 'composer.json',
        ]);
        $this->assertFinding($findings, 'composer.php_constraint', AuditSeverity::Info, [
            'present' => true,
            'constraint' => '^8.1 || ^8.2',
            'claim' => 'raw composer constraint only',
        ]);
        $this->assertFinding($findings, 'composer.dependencies.runtime', AuditSeverity::Info, [
            'scope' => 'runtime',
            'state' => 'valid',
            'complete' => true,
            'dependencies' => [
                ['name' => 'vendor/alpha', 'constraint' => '^1.0'],
                ['name' => 'vendor/zeta', 'constraint' => '^2.0'],
            ],
            'sort' => 'package name ascending',
        ]);
        $this->assertFinding($findings, 'composer.dependencies.development', AuditSeverity::Info, [
            'scope' => 'development',
            'state' => 'valid',
            'complete' => true,
            'dependencies' => [
                ['name' => 'mockery/mockery', 'constraint' => '^2.0'],
                ['name' => 'phpunit/phpunit', 'constraint' => '^13.0'],
            ],
            'sort' => 'package name ascending',
        ]);
    }

    public function testMissingPhpConstraintReportsNeutralEvidence(): void
    {
        $this->writeComposerJson(['require' => ['vendor/package' => '^1.0']]);

        $this->assertFinding($this->inspect(), 'composer.php_constraint', AuditSeverity::Info, [
            'present' => false,
            'constraint' => null,
            'claim' => 'no direct root PHP constraint found',
        ]);
    }

    public function testFrameworkEvidenceIsDetectedFromDirectPackages(): void
    {
        $this->writeComposerJson([
            'require' => [
                'symfony/framework-bundle' => '^7.0',
                'laravel/framework' => '^11.0',
                'evolvephp/core' => '^2.0',
                'cakephp/cakephp' => '^5.0',
                'yiisoft/yii2' => '^2.0',
                'vendor/package' => '^1.0',
            ],
            'require-dev' => [
                'symfony/symfony' => '^7.0',
                'evolvephp/dev-tools' => '^2.0',
            ],
        ]);

        $this->assertFinding($this->inspect(), 'composer.frameworks', AuditSeverity::Warning, [
            'dependency_state' => [
                'runtime' => 'valid',
                'development' => 'valid',
            ],
            'complete' => true,
            'frameworks' => [
                ['family' => 'cakephp', 'package' => 'cakephp/cakephp', 'scope' => 'runtime', 'constraint' => '^5.0'],
                ['family' => 'evolvephp', 'package' => 'evolvephp/core', 'scope' => 'runtime', 'constraint' => '^2.0'],
                ['family' => 'evolvephp', 'package' => 'evolvephp/dev-tools', 'scope' => 'development', 'constraint' => '^2.0'],
                ['family' => 'laravel', 'package' => 'laravel/framework', 'scope' => 'runtime', 'constraint' => '^11.0'],
                ['family' => 'symfony', 'package' => 'symfony/framework-bundle', 'scope' => 'runtime', 'constraint' => '^7.0'],
                ['family' => 'symfony', 'package' => 'symfony/symfony', 'scope' => 'development', 'constraint' => '^7.0'],
                ['family' => 'yii', 'package' => 'yiisoft/yii2', 'scope' => 'runtime', 'constraint' => '^2.0'],
            ],
            'claim' => 'direct composer package evidence only',
        ]);
    }

    public function testNoFrameworkEvidenceDoesNotClaimCompatibility(): void
    {
        $this->writeComposerJson(['require' => ['vendor/package' => '^1.0']]);

        $this->assertFinding($this->inspect(), 'composer.frameworks', AuditSeverity::Info, [
            'dependency_state' => [
                'runtime' => 'valid',
                'development' => 'absent',
            ],
            'complete' => true,
            'frameworks' => [],
            'claim' => 'no direct supported framework package evidence found',
        ]);
    }

    public function testPlatformOverrideIsCompatibilityReviewEvidenceOnly(): void
    {
        $this->writeComposerJson([
            'require' => ['php' => '^8.4'],
            'config' => ['platform' => ['php' => '8.1.99']],
        ]);

        $this->assertFinding($this->inspect(), 'composer.platform_php', AuditSeverity::Warning, [
            'present' => true,
            'value' => '8.1.99',
            'claim' => 'Composer platform emulation is not proof of the actual runtime PHP version',
        ]);
    }

    public function testMissingManifestReportsRiskFinding(): void
    {
        $this->assertFinding($this->inspect(), 'composer_json.unavailable', AuditSeverity::Risk, [
            'path' => 'composer.json',
            'reason' => 'missing or unreadable',
        ]);
    }

    public function testInvalidJsonReportsRiskFinding(): void
    {
        file_put_contents($this->path('composer.json'), '{"require":');

        $finding = $this->finding($this->inspect(), 'composer_json.invalid_json');

        self::assertSame(AuditSeverity::Risk, $finding->severity());
        self::assertSame('composer.json is not valid JSON.', $finding->message());
        self::assertSame('composer.json', $finding->evidence()['path']);
        self::assertArrayHasKey('error', $finding->evidence());
    }

    public function testNonObjectJsonRootReportsRiskFinding(): void
    {
        file_put_contents($this->path('composer.json'), '[]');

        $this->assertFinding($this->inspect(), 'composer_json.root_not_object', AuditSeverity::Risk, [
            'path' => 'composer.json',
            'actual' => 'list',
        ]);
    }

    public function testMalformedRequireReportsRiskFinding(): void
    {
        $this->writeComposerJson(['require' => 'not-an-object']);

        $this->assertFinding($this->inspect(), 'composer.require.malformed', AuditSeverity::Risk, [
            'field' => 'require',
            'actual' => 'string',
        ]);
    }

    public function testMalformedRequireDoesNotProduceKnownEmptyRuntimeDependencyEvidence(): void
    {
        $this->writeComposerJson(['require' => 'not-an-object']);

        $this->assertFinding($this->inspect(), 'composer.dependencies.runtime', AuditSeverity::Warning, [
            'scope' => 'runtime',
            'state' => 'unknown',
            'complete' => false,
            'dependencies' => null,
            'sort' => 'package name ascending',
        ]);
    }

    public function testMalformedRequireDoesNotProduceCompleteNoFrameworkEvidence(): void
    {
        $this->writeComposerJson(['require' => 'not-an-object']);

        $this->assertFinding($this->inspect(), 'composer.frameworks', AuditSeverity::Warning, [
            'dependency_state' => [
                'runtime' => 'unknown',
                'development' => 'absent',
            ],
            'complete' => false,
            'frameworks' => [],
            'claim' => 'framework evidence incomplete because dependency evidence is partial or unknown',
        ]);
    }

    public function testMalformedRequireDoesNotClaimPhpConstraintIsAbsent(): void
    {
        $this->writeComposerJson(['require' => 'not-an-object']);

        self::assertSame([
            'composer_json.present',
            'composer.require.malformed',
            'composer.dependencies.runtime',
            'composer.dependencies.development',
            'composer.frameworks',
        ], $this->findingIdentifiers($this->inspect()));
    }

    public function testMalformedRequireDevReportsRiskFinding(): void
    {
        $this->writeComposerJson(['require-dev' => ['valid/package' => '^1.0', 'bad/package' => false]]);

        $this->assertFinding($this->inspect(), 'composer.require_dev.malformed', AuditSeverity::Risk, [
            'field' => 'require-dev',
            'actual' => 'object with non-string package constraints',
        ]);
    }

    public function testMalformedRequireDevDoesNotProduceKnownEmptyDevelopmentDependencyEvidence(): void
    {
        $this->writeComposerJson(['require-dev' => 'not-an-object']);

        $this->assertFinding($this->inspect(), 'composer.dependencies.development', AuditSeverity::Warning, [
            'scope' => 'development',
            'state' => 'unknown',
            'complete' => false,
            'dependencies' => null,
            'sort' => 'package name ascending',
        ]);
    }

    public function testPartiallyMalformedRequireReportsValidDependenciesAsIncomplete(): void
    {
        $this->writeComposerJson([
            'require' => [
                'vendor/valid' => '^1.0',
                'vendor/malformed' => false,
            ],
        ]);

        $this->assertFinding($this->inspect(), 'composer.dependencies.runtime', AuditSeverity::Warning, [
            'scope' => 'runtime',
            'state' => 'partial',
            'complete' => false,
            'dependencies' => [
                ['name' => 'vendor/valid', 'constraint' => '^1.0'],
            ],
            'sort' => 'package name ascending',
        ]);
    }

    public function testMalformedFrameworkConstraintPreservesPackageNameEvidence(): void
    {
        $this->writeComposerJson([
            'require' => [
                'laravel/framework' => false,
                'vendor/valid' => '^1.0',
            ],
        ]);

        $this->assertFinding($this->inspect(), 'composer.frameworks', AuditSeverity::Warning, [
            'dependency_state' => [
                'runtime' => 'partial',
                'development' => 'absent',
            ],
            'complete' => false,
            'frameworks' => [
                [
                    'family' => 'laravel',
                    'package' => 'laravel/framework',
                    'scope' => 'runtime',
                    'constraint' => null,
                    'constraint_state' => 'malformed',
                ],
            ],
            'claim' => 'direct composer package evidence only',
        ]);
    }

    public function testMalformedPhpRequirementReportsRiskFinding(): void
    {
        $this->writeComposerJson(['require' => ['php' => []]]);

        $this->assertFinding($this->inspect(), 'composer.require_php.malformed', AuditSeverity::Risk, [
            'field' => 'require.php',
            'actual' => 'array',
        ]);
    }

    public function testMalformedPhpRequirementDoesNotClaimPhpConstraintIsAbsent(): void
    {
        $this->writeComposerJson(['require' => ['php' => [], 'vendor/package' => '^1.0']]);

        self::assertSame([
            'composer_json.present',
            'composer.require_php.malformed',
            'composer.dependencies.runtime',
            'composer.dependencies.development',
            'composer.frameworks',
        ], $this->findingIdentifiers($this->inspect()));
    }

    public function testMalformedPlatformOverrideReportsRiskFinding(): void
    {
        $this->writeComposerJson(['config' => ['platform' => ['php' => 80400]]]);

        $this->assertFinding($this->inspect(), 'composer.config_platform_php.malformed', AuditSeverity::Risk, [
            'field' => 'config.platform.php',
            'actual' => 'integer',
        ]);
    }

    public function testTargetAutoloadComposerScriptsAndPhpFilesAreNotExecuted(): void
    {
        mkdir($this->path('vendor'), 0777, true);
        mkdir($this->path('bootstrap'), 0777, true);

        file_put_contents($this->path('vendor/autoload.php'), "<?php file_put_contents(__DIR__ . '/../autoload-marker', 'loaded');\n");
        file_put_contents($this->path('bootstrap/trap.php'), "<?php file_put_contents(__DIR__ . '/../autoload-files-marker', 'loaded');\n");
        $this->writeComposerJson([
            'autoload' => ['files' => ['bootstrap/trap.php']],
            'scripts' => ['post-install-cmd' => ['@php -r "file_put_contents(\'script-marker\', \'ran\');"']],
        ]);

        $before = $this->targetInventory();
        $this->inspect();
        $after = $this->targetInventory();

        self::assertFileDoesNotExist($this->path('autoload-marker'));
        self::assertFileDoesNotExist($this->path('autoload-files-marker'));
        self::assertFileDoesNotExist($this->path('script-marker'));
        self::assertSame($before, $after);
    }

    public function testComposerAutoloadEvidenceAndSignalsAreBoundedDeterministicAndSeparated(): void
    {
        $this->writeComposerJson([
            'autoload-dev' => [
                'files' => ['tests/dev-file.php'],
                'psr-4' => [
                    'Tests\\Support\\' => 'tests/Support/',
                ],
            ],
            'autoload' => [
                'psr-0' => [
                    'Legacy\\' => 'legacy/',
                ],
                'classmap' => ['database/seeders', 'app/LegacyClass.php'],
                'files' => ['bootstrap/helpers.php', 'bootstrap/runtime.php'],
                'psr-4' => [
                    'App\\Billing\\' => ['src/Billing/', 'lib/Billing/'],
                    '' => 'fallback/',
                    'App\\' => 'src/',
                ],
            ],
        ]);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'composer.autoload', AuditSeverity::Info, [
            'complete' => true,
            'runtime' => [
                'state' => 'valid',
                'complete' => true,
                'psr-4' => [
                    ['prefix' => '', 'paths' => ['fallback/'], 'state' => 'valid'],
                    ['prefix' => 'App\\', 'paths' => ['src/'], 'state' => 'valid'],
                    ['prefix' => 'App\\Billing\\', 'paths' => ['src/Billing/', 'lib/Billing/'], 'state' => 'valid'],
                ],
                'psr-0' => [
                    ['prefix' => 'Legacy\\', 'paths' => ['legacy/'], 'state' => 'valid'],
                ],
                'classmap' => ['database/seeders', 'app/LegacyClass.php'],
                'files' => ['bootstrap/helpers.php', 'bootstrap/runtime.php'],
            ],
            'development' => [
                'state' => 'valid',
                'complete' => true,
                'psr-4' => [
                    ['prefix' => 'Tests\\Support\\', 'paths' => ['tests/Support/'], 'state' => 'valid'],
                ],
                'psr-0' => [],
                'classmap' => [],
                'files' => ['tests/dev-file.php'],
            ],
            'claim' => 'raw composer autoload metadata only; target autoload is not executed',
        ]);
        $this->assertFinding($findings, 'modernization.autoload_signals', AuditSeverity::Warning, [
            'complete' => true,
            'autoload_state' => [
                'runtime' => 'valid',
                'development' => 'valid',
            ],
            'candidates' => [
                ['kind' => 'runtime_psr4_namespace', 'prefix' => 'App\\', 'paths' => ['src/']],
                ['kind' => 'runtime_psr4_namespace', 'prefix' => 'App\\Billing\\', 'paths' => ['src/Billing/', 'lib/Billing/']],
            ],
            'review_signals' => [
                ['kind' => 'autoload_file', 'path' => 'bootstrap/helpers.php', 'signal' => 'requires migration review'],
                ['kind' => 'autoload_file', 'path' => 'bootstrap/runtime.php', 'signal' => 'requires migration review'],
            ],
            'claim' => 'structural review signals only; not proof of module or capability boundaries, migration feasibility, Bridge compatibility, or that migration is blocked',
        ]);
    }

    public function testMalformedComposerAutoloadPreservesValidSiblingEvidenceWithoutAbsenceClaims(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'Broken\\' => [false],
                ],
                'classmap' => ['database/seeders', false],
                'files' => 'bootstrap/helpers.php',
            ],
            'autoload-dev' => 'not-an-object',
        ]);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'composer.autoload.malformed', AuditSeverity::Risk, [
            'fields' => [
                ['field' => 'autoload.psr-4.Broken\\.0', 'actual' => 'boolean'],
                ['field' => 'autoload.classmap.1', 'actual' => 'boolean'],
                ['field' => 'autoload.files', 'actual' => 'string'],
                ['field' => 'autoload-dev', 'actual' => 'string'],
            ],
        ]);
        $this->assertFinding($findings, 'composer.autoload', AuditSeverity::Warning, [
            'complete' => false,
            'runtime' => [
                'state' => 'partial',
                'complete' => false,
                'psr-4' => [
                    ['prefix' => 'App\\', 'paths' => ['src/'], 'state' => 'valid'],
                    ['prefix' => 'Broken\\', 'paths' => [], 'state' => 'partial'],
                ],
                'psr-0' => [],
                'classmap' => ['database/seeders'],
                'files' => null,
            ],
            'development' => [
                'state' => 'unknown',
                'complete' => false,
                'psr-4' => null,
                'psr-0' => null,
                'classmap' => null,
                'files' => null,
            ],
            'claim' => 'raw composer autoload metadata only; target autoload is not executed',
        ]);
        $this->assertFinding($findings, 'modernization.autoload_signals', AuditSeverity::Warning, [
            'complete' => false,
            'autoload_state' => [
                'runtime' => 'partial',
                'development' => 'unknown',
            ],
            'candidates' => [
                ['kind' => 'runtime_psr4_namespace', 'prefix' => 'App\\', 'paths' => ['src/']],
            ],
            'review_signals' => [],
            'claim' => 'structural review signals only; not proof of module or capability boundaries, migration feasibility, Bridge compatibility, or that migration is blocked',
        ]);
    }

    public function testMalformedComposerClassmapAndFilesPreserveValidSiblingsAndRuntimeFileSignals(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'classmap' => ['database/seeders', false, 'app/Legacy.php'],
                'files' => ['bootstrap/helpers.php', false, 'bootstrap/runtime.php'],
            ],
        ]);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'composer.autoload.malformed', AuditSeverity::Risk, [
            'fields' => [
                ['field' => 'autoload.classmap.1', 'actual' => 'boolean'],
                ['field' => 'autoload.files.1', 'actual' => 'boolean'],
            ],
        ]);
        $this->assertFinding($findings, 'composer.autoload', AuditSeverity::Warning, [
            'complete' => false,
            'runtime' => [
                'state' => 'partial',
                'complete' => false,
                'psr-4' => [],
                'psr-0' => [],
                'classmap' => ['database/seeders', 'app/Legacy.php'],
                'files' => ['bootstrap/helpers.php', 'bootstrap/runtime.php'],
            ],
            'development' => [
                'state' => 'absent',
                'complete' => true,
                'psr-4' => [],
                'psr-0' => [],
                'classmap' => [],
                'files' => [],
            ],
            'claim' => 'raw composer autoload metadata only; target autoload is not executed',
        ]);
        $this->assertFinding($findings, 'modernization.autoload_signals', AuditSeverity::Warning, [
            'complete' => false,
            'autoload_state' => [
                'runtime' => 'partial',
                'development' => 'absent',
            ],
            'candidates' => [],
            'review_signals' => [
                ['kind' => 'autoload_file', 'path' => 'bootstrap/helpers.php', 'signal' => 'requires migration review'],
                ['kind' => 'autoload_file', 'path' => 'bootstrap/runtime.php', 'signal' => 'requires migration review'],
            ],
            'claim' => 'structural review signals only; not proof of module or capability boundaries, migration feasibility, Bridge compatibility, or that migration is blocked',
        ]);
    }

    public function testAutoloadModernizationSignalsAreInfoWhenOnlyNeutralAutoloadEvidenceExists(): void
    {
        foreach ([
            ['autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']]],
            ['autoload' => ['psr-0' => ['Legacy\\' => 'legacy/']]],
            ['autoload' => ['classmap' => ['app/Legacy.php']]],
            ['autoload' => ['psr-4' => ['' => 'fallback/']]],
            ['autoload' => []],
        ] as $manifest) {
            $root = $this->createProjectRoot();

            try {
                file_put_contents($root . DIRECTORY_SEPARATOR . 'composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));

                $finding = $this->finding((new ComposerProjectInspector())->inspect($root), 'modernization.autoload_signals');

                self::assertSame(AuditSeverity::Info, $finding->severity());
                self::assertSame([], $finding->evidence()['candidates']);
                self::assertSame([], $finding->evidence()['review_signals']);
            } finally {
                $this->removeDirectory($root);
            }
        }
    }

    public function testAutoloadSignalCompletenessUsesRuntimeSignalEvidenceOnly(): void
    {
        $this->writeComposerJson([
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
            'autoload-dev' => 'not-an-object',
        ]);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'composer.autoload', AuditSeverity::Warning, [
            'complete' => false,
            'runtime' => [
                'state' => 'valid',
                'complete' => true,
                'psr-4' => [
                    ['prefix' => 'App\\', 'paths' => ['src/'], 'state' => 'valid'],
                ],
                'psr-0' => [],
                'classmap' => [],
                'files' => [],
            ],
            'development' => [
                'state' => 'unknown',
                'complete' => false,
                'psr-4' => null,
                'psr-0' => null,
                'classmap' => null,
                'files' => null,
            ],
            'claim' => 'raw composer autoload metadata only; target autoload is not executed',
        ]);
        $this->assertFinding($findings, 'modernization.autoload_signals', AuditSeverity::Warning, [
            'complete' => true,
            'autoload_state' => [
                'runtime' => 'valid',
                'development' => 'unknown',
            ],
            'candidates' => [
                ['kind' => 'runtime_psr4_namespace', 'prefix' => 'App\\', 'paths' => ['src/']],
            ],
            'review_signals' => [],
            'claim' => 'structural review signals only; not proof of module or capability boundaries, migration feasibility, Bridge compatibility, or that migration is blocked',
        ]);
    }

    public function testMalformedRuntimeAutoloadMakesModernizationSignalsIncomplete(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => ['App\\' => ['src/', false]],
            ],
            'autoload-dev' => [
                'psr-4' => ['Tests\\' => 'tests/'],
            ],
        ]);

        $this->assertFinding($this->inspect(), 'modernization.autoload_signals', AuditSeverity::Info, [
            'complete' => false,
            'autoload_state' => [
                'runtime' => 'partial',
                'development' => 'valid',
            ],
            'candidates' => [],
            'review_signals' => [],
            'claim' => 'structural review signals only; not proof of module or capability boundaries, migration feasibility, Bridge compatibility, or that migration is blocked',
        ]);
    }

    public function testMalformedPsrPathListsPreserveValidSiblingsAndDoNotProduceCandidates(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => ['src/', false, 'legacy/'],
                ],
                'psr-0' => [
                    'Legacy\\' => ['legacy/src/', false, 'legacy/lib/'],
                ],
            ],
        ]);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'composer.autoload.malformed', AuditSeverity::Risk, [
            'fields' => [
                ['field' => 'autoload.psr-4.App\\.1', 'actual' => 'boolean'],
                ['field' => 'autoload.psr-0.Legacy\\.1', 'actual' => 'boolean'],
            ],
        ]);
        $this->assertFinding($findings, 'composer.autoload', AuditSeverity::Warning, [
            'complete' => false,
            'runtime' => [
                'state' => 'partial',
                'complete' => false,
                'psr-4' => [
                    ['prefix' => 'App\\', 'paths' => ['src/', 'legacy/'], 'state' => 'partial'],
                ],
                'psr-0' => [
                    ['prefix' => 'Legacy\\', 'paths' => ['legacy/src/', 'legacy/lib/'], 'state' => 'partial'],
                ],
                'classmap' => [],
                'files' => [],
            ],
            'development' => [
                'state' => 'absent',
                'complete' => true,
                'psr-4' => [],
                'psr-0' => [],
                'classmap' => [],
                'files' => [],
            ],
            'claim' => 'raw composer autoload metadata only; target autoload is not executed',
        ]);
        $this->assertFinding($findings, 'modernization.autoload_signals', AuditSeverity::Info, [
            'complete' => false,
            'autoload_state' => [
                'runtime' => 'partial',
                'development' => 'absent',
            ],
            'candidates' => [],
            'review_signals' => [],
            'claim' => 'structural review signals only; not proof of module or capability boundaries, migration feasibility, Bridge compatibility, or that migration is blocked',
        ]);
    }

    public function testAutoloadSignalCompletenessIgnoresMalformedRuntimePsr0AndClassmap(): void
    {
        $this->writeComposerJson([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
                'psr-0' => 'malformed',
                'classmap' => 'malformed',
                'files' => [],
            ],
        ]);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'composer.autoload', AuditSeverity::Warning, [
            'complete' => false,
            'runtime' => [
                'state' => 'partial',
                'complete' => false,
                'psr-4' => [
                    ['prefix' => 'App\\', 'paths' => ['src/'], 'state' => 'valid'],
                ],
                'psr-0' => [],
                'classmap' => null,
                'files' => [],
            ],
            'development' => [
                'state' => 'absent',
                'complete' => true,
                'psr-4' => [],
                'psr-0' => [],
                'classmap' => [],
                'files' => [],
            ],
            'claim' => 'raw composer autoload metadata only; target autoload is not executed',
        ]);
        $this->assertFinding($findings, 'modernization.autoload_signals', AuditSeverity::Warning, [
            'complete' => true,
            'autoload_state' => [
                'runtime' => 'partial',
                'development' => 'absent',
            ],
            'candidates' => [
                ['kind' => 'runtime_psr4_namespace', 'prefix' => 'App\\', 'paths' => ['src/']],
            ],
            'review_signals' => [],
            'claim' => 'structural review signals only; not proof of module or capability boundaries, migration feasibility, Bridge compatibility, or that migration is blocked',
        ]);
    }

    public function testEquivalentPropertyOrderingYieldsEquivalentOutput(): void
    {
        $firstRoot = $this->createProjectRoot();
        $secondRoot = $this->createProjectRoot();

        try {
            file_put_contents($firstRoot . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
                'require' => ['vendor/b' => '^2.0', 'php' => '^8.4', 'vendor/a' => '^1.0'],
                'require-dev' => ['tool/b' => '^2.0', 'tool/a' => '^1.0'],
                'autoload' => ['psr-4' => ['App\\Zeta\\' => 'src/Zeta/', 'App\\Alpha\\' => 'src/Alpha/']],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($secondRoot . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
                'autoload' => ['psr-4' => ['App\\Alpha\\' => 'src/Alpha/', 'App\\Zeta\\' => 'src/Zeta/']],
                'require-dev' => ['tool/a' => '^1.0', 'tool/b' => '^2.0'],
                'require' => ['vendor/a' => '^1.0', 'vendor/b' => '^2.0', 'php' => '^8.4'],
            ], JSON_THROW_ON_ERROR));

            $inspector = new ComposerProjectInspector();

            self::assertSame(
                $this->serializeFindings($inspector->inspect($firstRoot)),
                $this->serializeFindings($inspector->inspect($secondRoot)),
            );
        } finally {
            $this->removeDirectory($firstRoot);
            $this->removeDirectory($secondRoot);
        }
    }

    public function testEquivalentMalformedRequireOrderingYieldsEquivalentOutput(): void
    {
        $firstRoot = $this->createProjectRoot();
        $secondRoot = $this->createProjectRoot();

        try {
            file_put_contents($firstRoot . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
                'require' => [
                    'vendor/valid' => '^1.0',
                    'php' => [],
                    'vendor/malformed' => false,
                ],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($secondRoot . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
                'require' => [
                    'vendor/malformed' => false,
                    'php' => [],
                    'vendor/valid' => '^1.0',
                ],
            ], JSON_THROW_ON_ERROR));

            $inspector = new ComposerProjectInspector();

            self::assertSame(
                $this->serializeFindings($inspector->inspect($firstRoot)),
                $this->serializeFindings($inspector->inspect($secondRoot)),
            );
        } finally {
            $this->removeDirectory($firstRoot);
            $this->removeDirectory($secondRoot);
        }
    }

    public function testEquivalentPartiallyMalformedFrameworkOrderingYieldsEquivalentOutput(): void
    {
        $firstRoot = $this->createProjectRoot();
        $secondRoot = $this->createProjectRoot();

        try {
            file_put_contents($firstRoot . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
                'require' => [
                    'vendor/valid' => '^1.0',
                    'laravel/framework' => false,
                    'php' => '^8.4',
                ],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($secondRoot . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
                'require' => [
                    'php' => '^8.4',
                    'laravel/framework' => false,
                    'vendor/valid' => '^1.0',
                ],
            ], JSON_THROW_ON_ERROR));

            $inspector = new ComposerProjectInspector();

            self::assertSame(
                $this->serializeFindings($inspector->inspect($firstRoot)),
                $this->serializeFindings($inspector->inspect($secondRoot)),
            );
        } finally {
            $this->removeDirectory($firstRoot);
            $this->removeDirectory($secondRoot);
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeComposerJson(array $manifest): void
    {
        file_put_contents($this->path('composer.json'), json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * @return list<AuditFinding>
     */
    private function inspect(): array
    {
        return (new ComposerProjectInspector())->inspect($this->projectRoot);
    }

    /**
     * @param list<AuditFinding> $findings
     * @param array<string, mixed> $evidence
     */
    private function assertFinding(array $findings, string $identifier, AuditSeverity $severity, array $evidence): void
    {
        $finding = $this->finding($findings, $identifier);

        self::assertSame($severity, $finding->severity());
        self::assertSame($evidence, $finding->evidence());
    }

    /**
     * @param list<AuditFinding> $findings
     */
    private function finding(array $findings, string $identifier): AuditFinding
    {
        foreach ($findings as $finding) {
            if ($finding->identifier() === $identifier) {
                return $finding;
            }
        }

        self::fail('Missing finding: ' . $identifier);
    }

    /**
     * @param list<AuditFinding> $findings
     * @return list<string>
     */
    private function findingIdentifiers(array $findings): array
    {
        return array_map(
            static fn(AuditFinding $finding): string => $finding->identifier(),
            $findings,
        );
    }

    /**
     * @param list<AuditFinding> $findings
     * @return list<array{identifier: string, severity: string, message: string, evidence: array<string, mixed>}>
     */
    private function serializeFindings(array $findings): array
    {
        return array_map(
            static fn(AuditFinding $finding): array => [
                'identifier' => $finding->identifier(),
                'severity' => $finding->severity()->value,
                'message' => $finding->message(),
                'evidence' => $finding->evidence(),
            ],
            $findings,
        );
    }

    /**
     * @return array<string, array{size: int, hash: string}>
     */
    private function targetInventory(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($this->projectRoot) + 1));
                $files[$relativePath] = [
                    'size' => $file->getSize(),
                    'hash' => hash_file('sha256', $file->getPathname()),
                ];
            }
        }

        ksort($files);

        return $files;
    }

    private function path(string $relativePath): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function createProjectRoot(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evolvephp-composer-audit-test-' . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($path, 0777, true));

        return realpath($path) ?: $path;
    }

    private function removeDirectory(string $path): void
    {
        $real = realpath($path);

        if ($real === false) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entryPath = $entry->getPathname();

            if ($entry->isDir() && ! $entry->isLink()) {
                rmdir($entryPath);
                continue;
            }

            unlink($entryPath);
        }

        rmdir($real);
    }
}
