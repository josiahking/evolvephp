<?php

declare(strict_types=1);

use Evolve\Bridge\Contracts\BridgeContext;
use Evolve\DevTools\Adoption\AdoptionPlan;
use Evolve\DevTools\Adoption\DataMigrationState;
use Evolve\DevTools\Adoption\DataOwnership;
use Evolve\DevTools\Adoption\IntegrationMode;
use Evolve\DevTools\Adoption\MigrationManifest;
use Evolve\DevTools\Adoption\OwnershipSystem;
use Evolve\DevTools\Adoption\RouteOwner;
use Evolve\DevTools\Adoption\RouteOwnership;
use Evolve\DevTools\Audit\AuditRunner;
use Evolve\DevTools\Audit\Project\ComposerProjectInspector;
use Evolve\DevTools\Audit\Project\PhpSourceCouplingInspector;
use Evolve\DevTools\Audit\Project\PhpSourceStructureInspector;
use PHPUnit\Framework\TestCase;
use Tests\Support\ModernizationCutoverAcceptanceSupport;

require_once __DIR__ . '/../Support/ModernizationCutoverAcceptanceSupport.php';

final class EvolvePhp2ModernizationCutoverAcceptanceTest extends TestCase
{
    public function testAuditProvidesUsefulEvidenceBeforeMaintainerAdoptionDeclarations(): void
    {
        $legacyProject = $this->createLegacyBillingProjectFixture();

        $report = (new AuditRunner([
            new ComposerProjectInspector(),
            new PhpSourceCouplingInspector(),
            new PhpSourceStructureInspector(),
        ]))->inspect($legacyProject);

        $findings = $this->findingsByIdentifier($report->findings());

        self::assertArrayHasKey('composer_json.present', $findings);
        self::assertArrayHasKey('composer.frameworks', $findings);
        self::assertArrayHasKey('composer.autoload', $findings);
        self::assertArrayHasKey('modernization.autoload_signals', $findings);
        self::assertArrayHasKey('php_source.inventory', $findings);
        self::assertArrayHasKey('php_source.session_access', $findings);
        self::assertArrayHasKey('php_source.static_state_declaration', $findings);
        self::assertArrayHasKey('modernization.source_signals', $findings);

        self::assertSame([
            [
                'family' => 'laravel',
                'package' => 'laravel/framework',
                'scope' => 'runtime',
                'constraint' => '^8.0',
            ],
        ], $findings['composer.frameworks']->evidence()['frameworks']);
        self::assertContains('src/Billing/LegacyInvoiceSummary.php', $findings['php_source.inventory']->evidence()['inspected_paths']);
        self::assertStringContainsString('direct native session state access evidence only', $findings['php_source.session_access']->evidence()['claim']);
    }

    public function testAdoptionDeclarationsRecordParityCutoverRollbackAndRetirementEvidence(): void
    {
        $preCutover = new AdoptionPlan(
            new MigrationManifest(
                'billing.invoice-summary',
                IntegrationMode::Embedded,
                [
                    new RouteOwnership('/billing/invoices/summary', RouteOwner::Host, RouteOwner::Evolve),
                ],
                [
                    new DataOwnership(
                        'billing.invoice-summary.read-model',
                        'legacy-billing-store',
                        'evolve-billing-store',
                        OwnershipSystem::Host,
                        OwnershipSystem::Evolve,
                        DataMigrationState::LegacyAuthoritative,
                        DataMigrationState::EvolveAuthoritative,
                        'bounded parity sample comparison before cutover',
                    ),
                ],
                [
                    'Laravel, Symfony and legacy remote Bridge paths must normalize the same invoice summary result.',
                    'No host-only state, session data or private headers are forwarded to Evolve.',
                ],
                [
                    'BridgeContext request, correlation, principal and tenant identifiers are explicit.',
                    'Legacy remote callers must use the unchanged Remote Bridge v1 protocol envelope.',
                ],
            ),
            [
                'Audit findings were reviewed before adoption declarations were accepted.',
                'Reference legacy result was reconciled against each delegated Evolve path.',
            ],
            [
                'Host route ownership and legacy billing writer remain available before authoritative cutover.',
                'Rollback review was accepted before route and data authority move to Evolve.',
            ],
            [
                'invoice_count, total_amount, currency and capability identity match across all paths.',
                'Successful delegated operation executes the Evolve capability exactly once per path.',
            ],
        );

        self::assertSame('billing.invoice-summary', $preCutover->manifest()->capability());
        self::assertSame(RouteOwner::Host, $preCutover->manifest()->routeOwnership()[0]->currentOwner());
        self::assertSame(RouteOwner::Evolve, $preCutover->manifest()->routeOwnership()[0]->targetOwner());
        self::assertSame(OwnershipSystem::Host, $preCutover->manifest()->dataOwnership()[0]->currentWriter());
        self::assertSame(OwnershipSystem::Evolve, $preCutover->manifest()->dataOwnership()[0]->targetWriter());
        self::assertSame(DataMigrationState::LegacyAuthoritative, $preCutover->manifest()->dataOwnership()[0]->currentState());
        self::assertSame(DataMigrationState::EvolveAuthoritative, $preCutover->manifest()->dataOwnership()[0]->targetState());
        self::assertNotEmpty($preCutover->rollbackEvidence());
        self::assertNotEmpty($preCutover->acceptanceCriteria());

        $postCutover = new AdoptionPlan(
            new MigrationManifest(
                'billing.invoice-summary',
                IntegrationMode::Embedded,
                [
                    new RouteOwnership('/billing/invoices/summary', RouteOwner::Evolve, RouteOwner::Evolve),
                ],
                [
                    new DataOwnership(
                        'billing.invoice-summary.read-model',
                        'legacy-billing-store',
                        'evolve-billing-store',
                        OwnershipSystem::Evolve,
                        OwnershipSystem::Evolve,
                        DataMigrationState::EvolveAuthoritative,
                        DataMigrationState::EvolveAuthoritative,
                        null,
                    ),
                ],
                [
                    'Laravel, Symfony and legacy remote Bridge paths continue to normalize the same invoice summary result.',
                ],
                [
                    'BridgeContext remains the explicit request, correlation, principal and tenant boundary after cutover.',
                ],
            ),
            [
                'Accepted parity evidence promoted the route and writer authority to Evolve.',
            ],
            [
                'Rollback evidence was accepted before cutover and remains the cutover decision record.',
            ],
            [
                'Evolve is the current route owner and current data writer for billing.invoice-summary.',
            ],
        );

        self::assertSame('billing.invoice-summary', $postCutover->manifest()->capability());
        self::assertSame(RouteOwner::Evolve, $postCutover->manifest()->routeOwnership()[0]->currentOwner());
        self::assertSame(RouteOwner::Evolve, $postCutover->manifest()->routeOwnership()[0]->targetOwner());
        self::assertSame(OwnershipSystem::Evolve, $postCutover->manifest()->dataOwnership()[0]->currentWriter());
        self::assertSame(OwnershipSystem::Evolve, $postCutover->manifest()->dataOwnership()[0]->targetWriter());
        self::assertSame(DataMigrationState::EvolveAuthoritative, $postCutover->manifest()->dataOwnership()[0]->currentState());
        self::assertSame(DataMigrationState::EvolveAuthoritative, $postCutover->manifest()->dataOwnership()[0]->targetState());
        self::assertNotSame(OwnershipSystem::Host, $postCutover->manifest()->dataOwnership()[0]->currentWriter());

        $retirement = new AdoptionPlan(
            new MigrationManifest(
                'billing.invoice-summary',
                IntegrationMode::Embedded,
                [
                    new RouteOwnership('/billing/invoices/summary', RouteOwner::Evolve, RouteOwner::Evolve),
                ],
                [
                    new DataOwnership(
                        'billing.invoice-summary.read-model',
                        'legacy-billing-store',
                        'evolve-billing-store',
                        OwnershipSystem::Evolve,
                        OwnershipSystem::Evolve,
                        DataMigrationState::LegacyReadOnly,
                        DataMigrationState::LegacyRetired,
                        null,
                    ),
                ],
                [
                    'Legacy billing data is no longer authoritative during retirement acceptance.',
                ],
                [
                    'Retirement keeps Evolve as the sole authoritative writer.',
                ],
            ),
            [
                'Legacy read-only state was accepted after Evolve became authoritative.',
            ],
            [
                'Rollback compatibility ends after accepted legacy retirement; restoration would require a new migration plan.',
            ],
            [
                'Legacy state progresses from read-only to retired without restoring host write authority.',
            ],
        );

        self::assertSame(OwnershipSystem::Evolve, $retirement->manifest()->dataOwnership()[0]->currentWriter());
        self::assertSame(OwnershipSystem::Evolve, $retirement->manifest()->dataOwnership()[0]->targetWriter());
        self::assertSame(DataMigrationState::LegacyReadOnly, $retirement->manifest()->dataOwnership()[0]->currentState());
        self::assertSame(DataMigrationState::LegacyRetired, $retirement->manifest()->dataOwnership()[0]->targetState());
        self::assertNull($retirement->manifest()->dataOwnership()[0]->temporarySynchronization());
        self::assertNotSame(OwnershipSystem::Host, $retirement->manifest()->dataOwnership()[0]->currentWriter());
        self::assertContains(
            'Rollback compatibility ends after accepted legacy retirement; restoration would require a new migration plan.',
            $retirement->rollbackEvidence(),
        );
    }

    public function testLaravelSymfonyAndLegacyRemoteDelegateTheSameCapabilityWithParity(): void
    {
        $scenario = ModernizationCutoverAcceptanceSupport::scenario();
        $expected = $scenario['expected_result'];

        $laravel = ModernizationCutoverAcceptanceSupport::invokeLaravel($scenario);
        $symfony = ModernizationCutoverAcceptanceSupport::invokeSymfony($scenario);
        $remote = ModernizationCutoverAcceptanceSupport::invokeLegacyRemote($scenario);

        self::assertSame($expected, $laravel['result']);
        self::assertSame($expected, $symfony['result']);
        self::assertSame($expected, $remote['result']);
        self::assertSame($laravel['result'], $symfony['result']);
        self::assertSame($laravel['result'], $remote['result']);

        foreach ([$laravel, $symfony, $remote] as $delegation) {
            self::assertSame(1, $delegation['execution_count']);
            self::assertSame($scenario['request_id'], $delegation['context']['request_id']);
            self::assertSame($scenario['correlation_id'], $delegation['context']['correlation_id']);
            self::assertSame($scenario['principal_id'], $delegation['context']['principal_id']);
            self::assertSame($scenario['tenant_id'], $delegation['context']['tenant_id']);
            self::assertSame('billing.invoice-summary', $delegation['operation']);
            self::assertArrayNotHasKey('x-host-only-state', $delegation['forwarded_headers']);
        }
    }

    /**
     * @param list<object> $findings
     *
     * @return array<string, object>
     */
    private function findingsByIdentifier(array $findings): array
    {
        $indexed = [];

        foreach ($findings as $finding) {
            $indexed[$finding->identifier()] = $finding;
        }

        return $indexed;
    }

    private function createLegacyBillingProjectFixture(): string
    {
        $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'evolvephp-modernization-cutover-'
            . bin2hex(random_bytes(6));

        mkdir($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Billing', 0777, true);

        file_put_contents($root . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
            'require' => [
                'php' => '^7.4',
                'laravel/framework' => '^8.0',
            ],
            'autoload' => [
                'psr-4' => [
                    'LegacyBilling\\' => 'src/Billing/',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Billing' . DIRECTORY_SEPARATOR . 'LegacyInvoiceSummary.php', <<<'PHP'
<?php

namespace LegacyBilling;

final class LegacyInvoiceSummary
{
    private static array $lastTotals = [];

    public function summarize(array $amounts): array
    {
        session_start();
        $_SESSION['billing_invoice_summary_seen'] = true;
        self::$lastTotals[] = array_sum($amounts);

        return self::$lastTotals;
    }
}
PHP);

        return $root;
    }
}
