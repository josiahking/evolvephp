<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2BridgeModernisationRfcTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRfc0006ExistsWithAcceptedMetadata(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0006-evolve-bridge-and-incremental-modernisation.md');

        $this->assertMatchesPattern('/#\s*RFC\s+0006:\s*Evolve Bridge and Incremental Modernisation/i', $content);
        $this->assertMatchesPattern('/Status:\s*Accepted/i', $content);
        $this->assertMatchesPattern('/Target release:\s*EvolvePHP 2\.0 Beta/i', $content);
        $this->assertMatchesPattern('/Decision type:\s*Host integration, interoperability and migration architecture/i', $content);
        $this->assertMatchesPattern('/Depends on:\s*RFC 0001,\s*RFC 0002,\s*RFC 0003,\s*RFC 0004,\s*RFC 0005/i', $content);
    }

    public function testBridgePurposeModesAndCompatibilityAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0006-evolve-bridge-and-incremental-modernisation.md');

        $this->assertMatchesPattern('/adopt EvolvePHP 2 capability by capability|incremental adoption/is', $content);
        $this->assertMatchesPattern('/not an automatic code converter/i', $content);
        $this->assertMatchesPattern('/embedded\s+remote/is', $content);
        $this->assertMatchesPattern('/Sidecar is a deployment form of remote mode/i', $content);
        $this->assertMatchesPattern('/one coherent Composer dependency graph/i', $content);
        $this->assertMatchesPattern('/same PHP runtime|overlapping PHP support/is', $content);
        $this->assertMatchesPattern('/Legacy PHP applications below the EvolvePHP baseline must use remote or sidecar mode/i', $content);
    }

    public function testPackageBoundariesAndFrameworkAdaptersAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0006-evolve-bridge-and-incremental-modernisation.md');

        $this->assertMatchesPattern('/Core must never depend on a Bridge adapter|Evolve Core does not depend on Bridge/is', $content);
        $this->assertMatchesPattern('/Generic Bridge contracts must not contain Laravel or Symfony types|Contains no Laravel or Symfony types/i', $content);
        $this->assertMatchesPattern('/Laravel-specific translation/i', $content);
        $this->assertMatchesPattern('/Symfony-specific translation/i', $content);
        $this->assertMatchesPattern('/Host-framework types do not enter generic Core contracts/i', $content);
        $this->assertMatchesPattern('/Bridge is not a module or plugin synonym/i', $content);
    }

    public function testLifecycleRouteTranslationAndMiddlewareRulesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0006-evolve-bridge-and-incremental-modernisation.md');

        $this->assertMatchesPattern('/host owns.*top-level process lifecycle|host owns the top-level lifecycle/is', $content);
        $this->assertMatchesPattern('/Evolve owns after delegation.*execution-scope creation|Evolve owns.*execution lifecycle after delegation/is', $content);
        $this->assertMatchesPattern('/must not both boot Evolve|Evolve application must not boot twice/i', $content);
        $this->assertMatchesPattern('/exactly one Evolve execution scope|Create exactly one Evolve execution scope/i', $content);
        $this->assertMatchesPattern('/Route delegation must be explicit/i', $content);
        $this->assertMatchesPattern('/must not scan and override host routes silently|Hidden global route interception is forbidden/i', $content);
        $this->assertMatchesPattern('/Host request objects.*translated|translated into Evolve contracts/is', $content);
        $this->assertMatchesPattern('/Response headers through an allowlist|Header and metadata forwarding must use allowlists/i', $content);
        $this->assertMatchesPattern('/host middleware\s+Bridge translation middleware\s+Evolve middleware/is', $content);
    }

    public function testSecurityIdentitySessionAndContainerRulesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0006-evolve-bridge-and-incremental-modernisation.md');

        $this->assertMatchesPattern('/Authentication propagation.*validated|Security context must be validated, not assumed/is', $content);
        $this->assertMatchesPattern('/Evolve must authorize Evolve-owned operations/i', $content);
        $this->assertMatchesPattern('/Remote mode.*does not share native PHP session memory|does not share native PHP session memory/is', $content);
        $this->assertMatchesPattern('/Host and Evolve containers retain separate ownership/i', $content);
        $this->assertMatchesPattern('/Capability mapping must be explicit/i', $content);
        $this->assertMatchesPattern('/Embedded Bridge code, the host application and EvolvePHP execute inside one trusted PHP process/i', $content);
        $this->assertMatchesPattern('/Bridge is not a sandbox/i', $content);
        $this->assertMatchesPattern('/Remote mode requires.*network security boundary|Remote mode requires.*Network access restrictions/is', $content);
    }

    public function testDataTransactionsProtocolAndFailureRulesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0006-evolve-bridge-and-incremental-modernisation.md');

        $this->assertMatchesPattern('/One system must be the authoritative writer for an aggregate at a time/i', $content);
        $this->assertMatchesPattern('/default Bridge boundary is not a distributed transaction/i', $content);
        $this->assertMatchesPattern('/Uncoordinated dual writes are forbidden/i', $content);
        $this->assertMatchesPattern('/HTTP-based interoperability is the initial baseline/i', $content);
        $this->assertMatchesPattern('/JSON is the initial.*payload direction/i', $content);
        $this->assertMatchesPattern('/PHP serialization is forbidden/i', $content);
        $this->assertMatchesPattern('/Remote protocol has an explicit compatibility version|Protocol and contract versioning/i', $content);
        $this->assertMatchesPattern('/remote timeout does not prove rollback|timeout.*does not prove.*no work occurred|timeout.*does not prove that no side effect occurred/i', $content);
        $this->assertMatchesPattern('/State-changing operations must not be retried automatically without idempotency protection/i', $content);
        $this->assertMatchesPattern('/Automatic fallback is forbidden after partial or uncertain side effects/i', $content);
        $this->assertMatchesPattern('/Fail closed is the default for writes and security-sensitive operations/i', $content);
    }

    public function testReadinessCompatibilityMigrationAndRollbackAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0006-evolve-bridge-and-incremental-modernisation.md');

        $this->assertMatchesPattern('/Process liveness does not prove Bridge readiness/i', $content);
        $this->assertMatchesPattern('/Compatibility matrix/i', $content);
        $this->assertMatchesPattern('/Untested combinations must not be advertised as officially supported/i', $content);
        $this->assertMatchesPattern('/1\. Inventory the legacy capability.*15\. Remove legacy ownership after acceptance/is', $content);
        $this->assertMatchesPattern('/Shadow execution must not:.*Duplicate payments.*Create duplicate external side effects/is', $content);
        $this->assertMatchesPattern('/legacy authoritative\s+migration syncing\s+Evolve authoritative\s+legacy read-only\s+legacy retired/is', $content);
        $this->assertMatchesPattern('/Rollback is not merely changing a route flag/i', $content);
        $this->assertMatchesPattern('/Writes completed in Evolve may need reverse migration or compensation/i', $content);
        $this->assertMatchesPattern('/Legacy decommissioning/i', $content);
        $this->assertMatchesPattern('/Testing Requirements/i', $content);
    }

    public function testGovernanceSecurityAlternativesConsequencesIndexAndChangelogAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0006-evolve-bridge-and-incremental-modernisation.md');
        $index = $this->readProjectFile('docs/rfcs/README.md');
        $changelog = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/Security considerations/i', $content);
        $this->assertMatchesPattern('/Consequences and tradeoffs/i', $content);
        $this->assertMatchesPattern('/Alternatives considered/i', $content);
        $this->assertMatchesPattern('/Governance/i', $content);
        $this->assertMatchesPattern('/0006-evolve-bridge-and-incremental-modernisation\.md/i', $index);
        $this->assertMatchesPattern('/RFC 0006/i', $index);
        $this->assertMatchesPattern('/RFC 0006 defines host integration and incremental modernisation/i', $index);
        $this->assertMatchesPattern('/RFC 0007 will define Insight and OpenTelemetry architecture/i', $index);
        $this->assertMatchesPattern('/RFC 0006/i', $changelog);
        $this->assertMatchesPattern('/Evolve Bridge and Incremental Modernisation/i', $changelog);
    }

    private function readProjectFile($path)
    {
        $fullPath = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $this->assertFileExists($fullPath, $path . ' should exist before it is read.');

        return file_get_contents($fullPath);
    }

    private function assertMatchesPattern($pattern, $content)
    {
        $this->assertSame(1, preg_match($pattern, $content), 'Failed asserting that content matches ' . $pattern);
    }
}
