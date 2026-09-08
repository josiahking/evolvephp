<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Audit\Project;

use Evolve\DevTools\Audit\AuditFinding;
use Evolve\DevTools\Audit\AuditSeverity;
use Evolve\DevTools\Audit\Project\ComposerLockInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComposerLockInspectorTest extends TestCase
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

    public function testMissingLockfileReportsDeterministicIncompleteEvidence(): void
    {
        $this->assertFinding($this->inspect(), 'composer_lock.unavailable', AuditSeverity::Warning, [
            'path' => 'composer.lock',
            'reason' => 'missing or unreadable',
            'complete' => false,
        ]);
    }

    public function testInvalidJsonReportsRiskEvidence(): void
    {
        file_put_contents($this->path('composer.lock'), '{"packages":');

        $finding = $this->finding($this->inspect(), 'composer_lock.invalid_json');

        self::assertSame(AuditSeverity::Risk, $finding->severity());
        self::assertSame('composer.lock is not valid JSON.', $finding->message());
        self::assertSame('composer.lock', $finding->evidence()['path']);
        self::assertSame(false, $finding->evidence()['complete']);
        self::assertArrayHasKey('error', $finding->evidence());
    }

    public function testNonObjectRootReportsRiskEvidence(): void
    {
        file_put_contents($this->path('composer.lock'), '[]');

        $this->assertFinding($this->inspect(), 'composer_lock.root_not_object', AuditSeverity::Risk, [
            'path' => 'composer.lock',
            'actual' => 'list',
            'complete' => false,
        ]);
    }

    public function testPackageInventorySeparatesRuntimeAndDevelopmentPreservesVersionsAndSortsDeterministically(): void
    {
        $this->writeComposerLock([
            'packages-dev' => [
                ['name' => 'tools/zeta', 'version' => 'dev-main'],
                ['name' => 'tools/alpha', 'version' => '1.0.x-dev'],
            ],
            'packages' => [
                ['name' => 'vendor/zeta', 'version' => 'v1.2.3'],
                ['name' => 'vendor/alpha', 'version' => '2.0.0'],
            ],
        ]);

        $this->assertFinding($this->inspect(), 'composer_lock.package_inventory', AuditSeverity::Info, [
            'runtime' => [
                'state' => 'valid',
                'complete' => true,
                'packages' => [
                    ['name' => 'vendor/alpha', 'version' => '2.0.0', 'scope' => 'runtime'],
                    ['name' => 'vendor/zeta', 'version' => 'v1.2.3', 'scope' => 'runtime'],
                ],
            ],
            'development' => [
                'state' => 'valid',
                'complete' => true,
                'packages' => [
                    ['name' => 'tools/alpha', 'version' => '1.0.x-dev', 'scope' => 'development'],
                    ['name' => 'tools/zeta', 'version' => 'dev-main', 'scope' => 'development'],
                ],
            ],
            'complete' => true,
            'sort' => 'section scope then package name then version ascending',
        ]);
    }

    public function testRequirementGraphReportsNormalPackageEdgesWithoutPlatformRequirements(): void
    {
        $this->writeComposerLock([
            'packages' => [
                [
                    'name' => 'vendor/app',
                    'version' => '1.4.0',
                    'require' => [
                        'psr/log' => '^3.0',
                        'php' => '^8.4',
                        'vendor/lib' => '~2.0',
                        'ext-json' => '*',
                    ],
                ],
            ],
            'packages-dev' => [
                [
                    'name' => 'tools/dev',
                    'version' => 'dev-main',
                    'require' => [
                        'composer-runtime-api' => '^2.2',
                        'vendor/test-helper' => '^1.0',
                    ],
                ],
            ],
        ]);

        $this->assertFinding($this->inspect(), 'composer_lock.requirement_graph', AuditSeverity::Info, [
            'state' => 'valid',
            'complete' => true,
            'edges' => [
                [
                    'from' => 'tools/dev',
                    'from_version' => 'dev-main',
                    'scope' => 'development',
                    'to' => 'vendor/test-helper',
                    'constraint' => '^1.0',
                ],
                [
                    'from' => 'vendor/app',
                    'from_version' => '1.4.0',
                    'scope' => 'runtime',
                    'to' => 'psr/log',
                    'constraint' => '^3.0',
                ],
                [
                    'from' => 'vendor/app',
                    'from_version' => '1.4.0',
                    'scope' => 'runtime',
                    'to' => 'vendor/lib',
                    'constraint' => '~2.0',
                ],
            ],
            'sort' => 'scope then from package then from version then target package then constraint ascending',
        ]);
    }

    public function testMalformedNestedRequireConstraintMakesRequirementEvidencePartialWithoutDroppingValidSiblings(): void
    {
        $this->writeComposerLock([
            'packages' => [
                [
                    'name' => 'vendor/app',
                    'version' => '1.0.0',
                    'require' => [
                        'vendor/lib' => '^1.0',
                        'bad/lib' => false,
                        'php' => '^8.4',
                    ],
                ],
            ],
        ]);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'composer_lock.malformed', AuditSeverity::Risk, [
            'issues' => [
                [
                    'section' => 'packages',
                    'package' => 'vendor/app',
                    'field' => 'require',
                    'requirement' => 'bad/lib',
                    'reason' => 'constraint must be a string',
                    'actual' => 'boolean',
                ],
            ],
            'complete' => false,
            'sort' => 'section then package then field then requirement then reason then actual ascending',
        ]);
        $this->assertFinding($findings, 'composer_lock.package_inventory', AuditSeverity::Warning, [
            'runtime' => [
                'state' => 'partial',
                'complete' => false,
                'packages' => [
                    ['name' => 'vendor/app', 'version' => '1.0.0', 'scope' => 'runtime'],
                ],
            ],
            'development' => [
                'state' => 'absent',
                'complete' => true,
                'packages' => [],
            ],
            'complete' => false,
            'sort' => 'section scope then package name then version ascending',
        ]);
        $this->assertFinding($findings, 'composer_lock.requirement_graph', AuditSeverity::Warning, [
            'state' => 'partial',
            'complete' => false,
            'edges' => [
                [
                    'from' => 'vendor/app',
                    'from_version' => '1.0.0',
                    'scope' => 'runtime',
                    'to' => 'vendor/lib',
                    'constraint' => '^1.0',
                ],
            ],
            'sort' => 'scope then from package then from version then target package then constraint ascending',
        ]);
        $this->assertFinding($findings, 'composer_lock.platform_requirements', AuditSeverity::Warning, [
            'state' => 'partial',
            'complete' => false,
            'requirements' => [
                [
                    'source' => 'locked-package',
                    'package' => 'vendor/app',
                    'package_version' => '1.0.0',
                    'scope' => 'runtime',
                    'requirement' => 'php',
                    'constraint' => '^8.4',
                ],
            ],
            'sort' => 'source then scope then package then package version then requirement then constraint ascending',
        ]);
    }

    #[DataProvider('platformRequirementProvider')]
    public function testPlatformRequirementsAreReported(string $requirement): void
    {
        $this->writeComposerLock([
            'packages' => [
                [
                    'name' => 'vendor/app',
                    'version' => '1.0.0',
                    'require' => [
                        $requirement => '^1.0',
                        'vendor/normal' => '^2.0',
                    ],
                ],
            ],
        ]);

        $this->assertFinding($this->inspect(), 'composer_lock.platform_requirements', AuditSeverity::Info, [
            'state' => 'valid',
            'complete' => true,
            'requirements' => [
                [
                    'source' => 'locked-package',
                    'package' => 'vendor/app',
                    'package_version' => '1.0.0',
                    'scope' => 'runtime',
                    'requirement' => $requirement,
                    'constraint' => '^1.0',
                ],
            ],
            'sort' => 'source then scope then package then package version then requirement then constraint ascending',
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function platformRequirementProvider(): array
    {
        return [
            'php' => ['php'],
            'php family' => ['php-64bit'],
            'extension' => ['ext-json'],
            'library' => ['lib-curl'],
            'composer' => ['composer'],
            'composer plugin api' => ['composer-plugin-api'],
            'composer runtime api' => ['composer-runtime-api'],
        ];
    }

    public function testRootPlatformAndPlatformDevEvidenceIsDistinguishedFromPackageRequirements(): void
    {
        $this->writeComposerLock([
            'platform-dev' => ['ext-xdebug' => '*'],
            'packages' => [
                [
                    'name' => 'vendor/app',
                    'version' => '1.0.0',
                    'require' => ['php' => '^8.4'],
                ],
            ],
            'platform' => ['php' => '^8.4', 'ext-json' => '*'],
        ]);

        $this->assertFinding($this->inspect(), 'composer_lock.platform_requirements', AuditSeverity::Info, [
            'state' => 'valid',
            'complete' => true,
            'requirements' => [
                [
                    'source' => 'locked-package',
                    'package' => 'vendor/app',
                    'package_version' => '1.0.0',
                    'scope' => 'runtime',
                    'requirement' => 'php',
                    'constraint' => '^8.4',
                ],
                [
                    'source' => 'root-development',
                    'scope' => 'development',
                    'requirement' => 'ext-xdebug',
                    'constraint' => '*',
                ],
                [
                    'source' => 'root-runtime',
                    'scope' => 'runtime',
                    'requirement' => 'ext-json',
                    'constraint' => '*',
                ],
                [
                    'source' => 'root-runtime',
                    'scope' => 'runtime',
                    'requirement' => 'php',
                    'constraint' => '^8.4',
                ],
            ],
            'sort' => 'source then scope then package then package version then requirement then constraint ascending',
        ]);
    }

    public function testComposerPluginPackagesAreReportedWithoutFalsePositivesOrExecution(): void
    {
        mkdir($this->path('vendor/plugin/src'), 0777, true);
        file_put_contents($this->path('vendor/plugin/src/Plugin.php'), "<?php file_put_contents(__DIR__ . '/../../../plugin-code-marker', 'loaded');\n");
        $this->writeComposerLock([
            'packages' => [
                ['name' => 'vendor/library', 'version' => '1.0.0', 'type' => 'library'],
                ['name' => 'vendor/plugin', 'version' => '2.0.0', 'type' => 'composer-plugin'],
            ],
        ]);

        $this->assertFinding($this->inspect(), 'composer_lock.composer_plugins', AuditSeverity::Warning, [
            'state' => 'valid',
            'complete' => true,
            'plugins' => [
                ['name' => 'vendor/plugin', 'version' => '2.0.0', 'scope' => 'runtime'],
            ],
            'sort' => 'scope then package name then version ascending',
        ]);
        self::assertFileDoesNotExist($this->path('plugin-code-marker'));
    }

    public function testMalformedTypeMetadataMakesComposerPluginEvidenceIncompleteWithoutDroppingInventory(): void
    {
        $this->writeComposerLock([
            'packages' => [
                [
                    'name' => 'vendor/package',
                    'version' => '1.0.0',
                    'type' => false,
                ],
            ],
        ]);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'composer_lock.malformed', AuditSeverity::Risk, [
            'issues' => [
                [
                    'section' => 'packages',
                    'package' => 'vendor/package',
                    'field' => 'type',
                    'reason' => 'must be a string when present',
                    'actual' => 'boolean',
                ],
            ],
            'complete' => false,
            'sort' => 'section then package then field then requirement then reason then actual ascending',
        ]);
        $this->assertFinding($findings, 'composer_lock.package_inventory', AuditSeverity::Warning, [
            'runtime' => [
                'state' => 'partial',
                'complete' => false,
                'packages' => [
                    ['name' => 'vendor/package', 'version' => '1.0.0', 'scope' => 'runtime'],
                ],
            ],
            'development' => [
                'state' => 'absent',
                'complete' => true,
                'packages' => [],
            ],
            'complete' => false,
            'sort' => 'section scope then package name then version ascending',
        ]);
        $this->assertFinding($findings, 'composer_lock.composer_plugins', AuditSeverity::Warning, [
            'state' => 'partial',
            'complete' => false,
            'plugins' => [],
            'sort' => 'scope then package name then version ascending',
        ]);
    }

    public function testAbandonedPackagesAreReportedWhileFalseIsOmitted(): void
    {
        $this->writeComposerLock([
            'packages' => [
                ['name' => 'vendor/false', 'version' => '1.0.0', 'abandoned' => false],
                ['name' => 'vendor/true', 'version' => '1.1.0', 'abandoned' => true],
            ],
            'packages-dev' => [
                ['name' => 'tools/string', 'version' => 'dev-main', 'abandoned' => 'tools/replacement'],
            ],
        ]);

        $this->assertFinding($this->inspect(), 'composer_lock.abandoned_packages', AuditSeverity::Warning, [
            'state' => 'valid',
            'complete' => true,
            'packages' => [
                ['name' => 'tools/string', 'version' => 'dev-main', 'scope' => 'development', 'abandoned' => 'tools/replacement'],
                ['name' => 'vendor/true', 'version' => '1.1.0', 'scope' => 'runtime', 'abandoned' => true],
            ],
            'sort' => 'scope then package name then version then abandoned value kind then abandoned value ascending',
        ]);
    }

    public function testDuplicateAbandonedPackageIdentitiesSortByAbandonedKindAndValue(): void
    {
        $first = [
            'packages' => [
                ['name' => 'vendor/legacy', 'version' => '1.0.0', 'abandoned' => true],
                ['name' => 'vendor/legacy', 'version' => '1.0.0', 'abandoned' => 'vendor/replacement'],
            ],
        ];
        $second = [
            'packages' => [
                ['name' => 'vendor/legacy', 'version' => '1.0.0', 'abandoned' => 'vendor/replacement'],
                ['name' => 'vendor/legacy', 'version' => '1.0.0', 'abandoned' => true],
            ],
        ];

        self::assertSame($this->findingsForLock($first), $this->findingsForLock($second));
    }

    public function testEmptyStringAbandonedMetadataIsMalformedIncompleteEvidence(): void
    {
        $this->writeComposerLock([
            'packages' => [
                ['name' => 'vendor/legacy', 'version' => '1.0.0', 'abandoned' => ''],
            ],
        ]);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'composer_lock.malformed', AuditSeverity::Risk, [
            'issues' => [
                [
                    'section' => 'packages',
                    'package' => 'vendor/legacy',
                    'field' => 'abandoned',
                    'reason' => 'must be boolean or non-empty string',
                    'actual' => 'empty string',
                ],
            ],
            'complete' => false,
            'sort' => 'section then package then field then requirement then reason then actual ascending',
        ]);
        $this->assertFinding($findings, 'composer_lock.package_inventory', AuditSeverity::Warning, [
            'runtime' => [
                'state' => 'partial',
                'complete' => false,
                'packages' => [
                    ['name' => 'vendor/legacy', 'version' => '1.0.0', 'scope' => 'runtime'],
                ],
            ],
            'development' => [
                'state' => 'absent',
                'complete' => true,
                'packages' => [],
            ],
            'complete' => false,
            'sort' => 'section scope then package name then version ascending',
        ]);
        $this->assertFinding($findings, 'composer_lock.abandoned_packages', AuditSeverity::Warning, [
            'state' => 'partial',
            'complete' => false,
            'packages' => [],
            'sort' => 'scope then package name then version then abandoned value kind then abandoned value ascending',
        ]);
    }

    public function testUnknownPackageSectionPreservesUnknownAbandonedEvidenceState(): void
    {
        $this->writeComposerLock([
            'packages' => ['not' => 'a list'],
        ]);

        $this->assertFinding($this->inspect(), 'composer_lock.abandoned_packages', AuditSeverity::Warning, [
            'state' => 'unknown',
            'complete' => false,
            'packages' => [],
            'sort' => 'scope then package name then version then abandoned value kind then abandoned value ascending',
        ]);
    }

    public function testUnknownAndPartialPackageSectionsPreserveUnknownAbandonedEvidenceState(): void
    {
        $this->writeComposerLock([
            'packages' => ['not' => 'a list'],
            'packages-dev' => [
                [
                    'name' => 'tools/legacy',
                    'version' => '1.0.0',
                    'abandoned' => [],
                ],
            ],
        ]);

        $this->assertFinding($this->inspect(), 'composer_lock.abandoned_packages', AuditSeverity::Warning, [
            'state' => 'unknown',
            'complete' => false,
            'packages' => [],
            'sort' => 'scope then package name then version then abandoned value kind then abandoned value ascending',
        ]);
    }

    public function testMalformedAbandonedMetadataReportsIncompleteEvidence(): void
    {
        $this->writeComposerLock([
            'packages' => [
                ['name' => 'vendor/bad', 'version' => '1.0.0', 'abandoned' => ['vendor/replacement']],
            ],
        ]);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'composer_lock.malformed', AuditSeverity::Risk, [
            'issues' => [
                [
                    'section' => 'packages',
                    'package' => 'vendor/bad',
                    'field' => 'abandoned',
                    'reason' => 'must be boolean or non-empty string',
                    'actual' => 'list',
                ],
            ],
            'complete' => false,
            'sort' => 'section then package then field then requirement then reason then actual ascending',
        ]);
        $this->assertFinding($findings, 'composer_lock.abandoned_packages', AuditSeverity::Warning, [
            'state' => 'partial',
            'complete' => false,
            'packages' => [],
            'sort' => 'scope then package name then version then abandoned value kind then abandoned value ascending',
        ]);
    }

    public function testMalformedPackageEntryDoesNotEraseValidSiblingEvidence(): void
    {
        $this->writeComposerLock([
            'packages' => [
                ['name' => 'vendor/valid', 'version' => '1.0.0'],
                ['name' => '', 'version' => '2.0.0'],
                ['name' => 'vendor/no-version'],
            ],
        ]);

        $findings = $this->inspect();

        $this->assertFinding($findings, 'composer_lock.package_inventory', AuditSeverity::Warning, [
            'runtime' => [
                'state' => 'partial',
                'complete' => false,
                'packages' => [
                    ['name' => 'vendor/valid', 'version' => '1.0.0', 'scope' => 'runtime'],
                ],
            ],
            'development' => [
                'state' => 'absent',
                'complete' => true,
                'packages' => [],
            ],
            'complete' => false,
            'sort' => 'section scope then package name then version ascending',
        ]);
        $this->assertFinding($findings, 'composer_lock.malformed', AuditSeverity::Risk, [
            'issues' => [
                [
                    'section' => 'packages',
                    'field' => 'name',
                    'reason' => 'must be a non-empty string',
                    'actual' => 'empty string',
                ],
                [
                    'section' => 'packages',
                    'field' => 'version',
                    'reason' => 'must be a non-empty string',
                    'actual' => 'missing',
                    'package' => 'vendor/no-version',
                ],
            ],
            'complete' => false,
            'sort' => 'section then package then field then requirement then reason then actual ascending',
        ]);
    }

    public function testMalformedRequireMapsDoNotProduceFakeCompleteGraphEvidence(): void
    {
        $this->writeComposerLock([
            'packages' => [
                ['name' => 'vendor/app', 'version' => '1.0.0', 'require' => ['vendor/lib' => '^1.0', 'bad/lib' => false]],
                ['name' => 'vendor/unknown', 'version' => '2.0.0', 'require' => 'not-an-object'],
            ],
        ]);

        $this->assertFinding($this->inspect(), 'composer_lock.requirement_graph', AuditSeverity::Warning, [
            'state' => 'partial',
            'complete' => false,
            'edges' => [
                [
                    'from' => 'vendor/app',
                    'from_version' => '1.0.0',
                    'scope' => 'runtime',
                    'to' => 'vendor/lib',
                    'constraint' => '^1.0',
                ],
            ],
            'sort' => 'scope then from package then from version then target package then constraint ascending',
        ]);
    }

    public function testEquivalentMalformedLockfilesProduceEquivalentFindings(): void
    {
        $first = [
            'packages' => [
                ['name' => 'vendor/valid', 'version' => '1.0.0', 'require' => ['bad/lib' => false, 'vendor/lib' => '^1.0']],
                ['version' => '2.0.0', 'name' => ''],
            ],
        ];
        $second = [
            'packages' => [
                ['name' => '', 'version' => '2.0.0'],
                ['version' => '1.0.0', 'require' => ['vendor/lib' => '^1.0', 'bad/lib' => false], 'name' => 'vendor/valid'],
            ],
        ];

        self::assertSame($this->findingsForLock($first), $this->findingsForLock($second));
    }

    public function testEquivalentMalformedEntriesWithEqualPrimarySortKeysProduceEquivalentFindings(): void
    {
        $first = [
            'packages' => [
                ['version' => '1.0.0'],
                ['name' => '', 'version' => '1.0.0'],
            ],
        ];
        $second = [
            'packages' => [
                ['name' => '', 'version' => '1.0.0'],
                ['version' => '1.0.0'],
            ],
        ];

        self::assertSame($this->findingsForLock($first), $this->findingsForLock($second));
    }

    public function testEquivalentLockfilesWithDifferentOrderingSerializeEquivalently(): void
    {
        $first = [
            'platform-dev' => ['ext-xdebug' => '*'],
            'packages-dev' => [
                ['name' => 'tools/b', 'version' => 'dev-main', 'require' => ['vendor/b' => '^2.0', 'php' => '^8.4']],
                ['name' => 'tools/a', 'version' => '1.0.x-dev', 'abandoned' => 'tools/replacement'],
            ],
            'platform' => ['ext-json' => '*', 'php' => '^8.4'],
            'packages' => [
                ['name' => 'vendor/plugin', 'version' => '2.0.0', 'type' => 'composer-plugin'],
                ['name' => 'vendor/app', 'version' => 'v1.2.3', 'require' => ['psr/log' => '^3.0', 'ext-json' => '*']],
            ],
        ];
        $second = [
            'packages' => [
                ['require' => ['ext-json' => '*', 'psr/log' => '^3.0'], 'version' => 'v1.2.3', 'name' => 'vendor/app'],
                ['type' => 'composer-plugin', 'version' => '2.0.0', 'name' => 'vendor/plugin'],
            ],
            'platform' => ['php' => '^8.4', 'ext-json' => '*'],
            'packages-dev' => [
                ['abandoned' => 'tools/replacement', 'version' => '1.0.x-dev', 'name' => 'tools/a'],
                ['require' => ['php' => '^8.4', 'vendor/b' => '^2.0'], 'version' => 'dev-main', 'name' => 'tools/b'],
            ],
            'platform-dev' => ['ext-xdebug' => '*'],
        ];

        self::assertSame($this->findingsForLock($first), $this->findingsForLock($second));
    }

    public function testSafetyBoundaryDoesNotExecuteTargetFilesOrMutateInventory(): void
    {
        mkdir($this->path('vendor/composer'), 0777, true);
        mkdir($this->path('vendor/plugin/src'), 0777, true);
        mkdir($this->path('src'), 0777, true);

        file_put_contents($this->path('vendor/autoload.php'), "<?php file_put_contents(__DIR__ . '/../autoload-marker', 'loaded');\n");
        file_put_contents($this->path('vendor/plugin/src/Plugin.php'), "<?php file_put_contents(__DIR__ . '/../../../plugin-marker', 'loaded');\n");
        file_put_contents($this->path('src/Trap.php'), "<?php file_put_contents(__DIR__ . '/../source-marker', 'loaded');\n");
        file_put_contents($this->path('composer.json'), json_encode([
            'scripts' => ['post-install-cmd' => ['@php -r "file_put_contents(\'script-marker\', \'ran\');"']],
            'autoload' => ['files' => ['src/Trap.php']],
        ], JSON_THROW_ON_ERROR));
        $this->writeComposerLock([
            'packages' => [
                [
                    'name' => 'vendor/plugin',
                    'version' => '2.0.0',
                    'type' => 'composer-plugin',
                    'autoload' => ['psr-4' => ['Vendor\\Plugin\\' => 'vendor/plugin/src']],
                ],
            ],
        ]);

        $before = $this->targetInventory();
        $this->inspect();
        $after = $this->targetInventory();

        self::assertFileDoesNotExist($this->path('autoload-marker'));
        self::assertFileDoesNotExist($this->path('plugin-marker'));
        self::assertFileDoesNotExist($this->path('source-marker'));
        self::assertFileDoesNotExist($this->path('script-marker'));
        self::assertSame($before, $after);
    }

    public function testStructurallyValidEmptyLockfileReportsNeutralInventoryEvidence(): void
    {
        file_put_contents($this->path('composer.lock'), '{}');

        $this->assertFinding($this->inspect(), 'composer_lock.package_inventory', AuditSeverity::Info, [
            'runtime' => [
                'state' => 'absent',
                'complete' => true,
                'packages' => [],
            ],
            'development' => [
                'state' => 'absent',
                'complete' => true,
                'packages' => [],
            ],
            'complete' => true,
            'sort' => 'section scope then package name then version ascending',
        ]);
    }

    /**
     * @param array<string, mixed> $lock
     */
    private function writeComposerLock(array $lock): void
    {
        file_put_contents($this->path('composer.lock'), json_encode($lock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * @return list<AuditFinding>
     */
    private function inspect(): array
    {
        return (new ComposerLockInspector())->inspect($this->projectRoot);
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
     * @param array<string, mixed> $lock
     * @return list<array{identifier: string, severity: string, message: string, evidence: array<string, mixed>}>
     */
    private function findingsForLock(array $lock): array
    {
        $root = $this->createProjectRoot();

        try {
            file_put_contents($root . DIRECTORY_SEPARATOR . 'composer.lock', json_encode($lock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return $this->serializeFindings((new ComposerLockInspector())->inspect($root));
        } finally {
            $this->removeDirectory($root);
        }
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
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evolvephp-composer-lock-audit-test-' . bin2hex(random_bytes(8));

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
