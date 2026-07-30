<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2RfcConsistencyTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testAcceptedRfcsExistAndKeepDependencyChain(): void
    {
        $rfcs = array(
            '0001' => 'docs/rfcs/0001-evolvephp-2-vision-and-scope.md',
            '0002' => 'docs/rfcs/0002-terminology-package-boundaries-and-public-contracts.md',
            '0003' => 'docs/rfcs/0003-php-versioning-compatibility-and-release-policy.md',
            '0004' => 'docs/rfcs/0004-module-and-plugin-lifecycle.md',
            '0005' => 'docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md',
            '0006' => 'docs/rfcs/0006-evolve-bridge-and-incremental-modernisation.md',
        );

        foreach ($rfcs as $number => $path) {
            $content = $this->readProjectFile($path);

            $this->assertMatchesPattern('/#\s*RFC\s+' . $number . ':/i', $content);
            $this->assertMatchesPattern('/Status:\s*Accepted/i', $content);
            $this->assertMatchesPattern('/Superseded by:\s*None/i', $content);
        }

        $this->assertMatchesPattern('/Depends on:\s*RFC 0001/i', $this->readProjectFile($rfcs['0002']));
        $this->assertMatchesPattern('/Depends on:\s*RFC 0001,\s*RFC 0002/i', $this->readProjectFile($rfcs['0003']));
        $this->assertMatchesPattern('/Depends on:\s*RFC 0001,\s*RFC 0002,\s*RFC 0003/i', $this->readProjectFile($rfcs['0004']));
        $this->assertMatchesPattern('/Depends on:\s*RFC 0001,\s*RFC 0002,\s*RFC 0003,\s*RFC 0004/i', $this->readProjectFile($rfcs['0005']));
        $this->assertMatchesPattern('/Depends on:\s*RFC 0001,\s*RFC 0002,\s*RFC 0003,\s*RFC 0004,\s*RFC 0005/i', $this->readProjectFile($rfcs['0006']));
    }

    public function testRfc0001UsesRfc0005ExecutionScopeTerminologyForTenantContext(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0001-evolvephp-2-vision-and-scope.md');

        $this->assertMatchesPattern('/Execution-scoped state, including request, message, job, user and tenant context, must not leak across executions or persistent-worker reuse/i', $content);
        $this->assertMatchesPattern('/RFC 0005 defines application, execution and transient lifetimes/i', $content);
        $this->assertMatchesPattern('/Tenant context may exist when supplied by an application/i', $content);
        $this->assertMatchesPattern('/Tenant context belongs to execution scope/i', $content);
        $this->assertMatchesPattern('/RFC 0001 does not establish a separate tenant-scoped container/i', $content);
        $this->assertMatchesPattern('/Multitenancy remains deferred/i', $content);
        $this->assertDoesNotMatchPattern('/Request-scoped and tenant-scoped state/i', $content);
    }

    public function testRfc0001KeepsBridgeAndPluginTerminologySeparate(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0001-evolvephp-2-vision-and-scope.md');

        $this->assertMatchesPattern('/A Bridge adapter is an integration-adapter category/i', $content);
        $this->assertMatchesPattern('/Bridge and Plugin are not synonyms/i', $content);
        $this->assertMatchesPattern('/A specific Bridge adapter may participate through approved registration or plugin lifecycle contracts/i', $content);
        $this->assertMatchesPattern('/Participation in lifecycle does not automatically redefine the adapter as a plugin/i', $content);
        $this->assertMatchesPattern('/RFC 0004 remains authoritative for Plugin terminology/i', $content);
        $this->assertMatchesPattern('/RFC 0006 remains authoritative for Bridge terminology/i', $content);
        $this->assertDoesNotMatchPattern(
            '/-\s*Host framework bridge\./i',
            $this->extractSection($content, 'Module Versus Plugin Distinction')
        );
    }

    public function testRfc0001EmbeddedOwnershipSummaryMatchesRfc0006(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0001-evolvephp-2-vision-and-scope.md');

        $this->assertMatchesPattern('/The host owns the top-level process and browser-facing application boundary/i', $content);
        $this->assertMatchesPattern('/The host normally owns its existing session and identity-establishment infrastructure/i', $content);
        $this->assertMatchesPattern('/The host owns the outer routing and delegation decision/i', $content);
        $this->assertMatchesPattern('/Evolve validates translated identity assertions/i', $content);
        $this->assertMatchesPattern('/Evolve authorizes Evolve-owned operations/i', $content);
        $this->assertMatchesPattern('/Additional Evolve authentication or step-up validation must be explicit/i', $content);
        $this->assertMatchesPattern('/A delegated route or capability has one authoritative owner/i', $content);
        $this->assertMatchesPattern('/RFC 0006 remains authoritative for detailed authentication, authorization, route and session boundaries/i', $content);
    }

    public function testRfc0001DistinguishesBetaBridgeFoundationsFromAdvancedRemoteExtraction(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0001-evolvephp-2-vision-and-scope.md');

        $this->assertMatchesPattern('/RFC 0006 governs the Bridge architecture targeted toward Beta foundations/i', $content);
        $this->assertMatchesPattern('/Beta does not promise every Bridge adapter or full advanced remote-module extraction/i', $content);
        $this->assertMatchesPattern('/Advanced service extraction, distributed workflow tooling and mature remote-module orchestration remain deferred unless a later RFC moves them/i', $content);
    }

    public function testRfc0005TenantSectionHasOneNormativeTenantContextOwnershipStatement(): void
    {
        $section = $this->extractSection(
            $this->readProjectFile('docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md'),
            'Tenant Isolation Boundary'
        );

        $this->assertSame(
            1,
            preg_match_all('/tenant context.*belongs to execution scope/i', $section),
            'Tenant context ownership should be stated once in the tenant isolation section.'
        );
        $this->assertMatchesPattern('/A later execution without tenant context must not inherit one/i', $section);
        $this->assertMatchesPattern('/Application singletons must not retain a current tenant/i', $section);
        $this->assertMatchesPattern('/Static current-tenant helpers are forbidden/i', $section);
        $this->assertMatchesPattern('/tests must alternate tenant identifiers/i', $section);
    }

    public function testRfc0005OrdersTelemetryFinalizationBeforeScopeClosureAndDetachedExportAfterClosure(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md');

        $this->assertMatchesPattern('/Run execution termination hooks.*Finish active execution telemetry.*Detach active trace, span and propagation context.*Close execution-scoped resources.*Reset reusable application-lifetime participants.*Clear any remaining ambient execution context.*Perform bounded export or flush using detached immutable telemetry data.*Decide whether the process is safe for reuse/is', $content);
        $this->assertMatchesPattern('/Active trace, span and propagation context must not survive execution-scope closure/i', $content);
        $this->assertMatchesPattern('/Termination hooks may still generate telemetry before active telemetry is finalized/i', $content);
        $this->assertMatchesPattern('/Post-closure export or flush must operate only on detached data/i', $content);
        $this->assertMatchesPattern('/Export must not reactivate the closed execution context/i', $content);
        $this->assertMatchesPattern('/Export or flush must be bounded/i', $content);
        $this->assertMatchesPattern('/An exporter failure does not normally corrupt application state/i', $content);
        $this->assertMatchesPattern('/Failure to detach or clear active telemetry context prevents safe worker reuse/i', $content);
        $this->assertMatchesPattern('/RFC 0007 may refine APIs, spans, metrics, logs and exporter placement without weakening isolation/i', $content);
        $this->assertMatchesPattern('/handler result or handler exception is the primary execution outcome/i', $content);
        $this->assertMatchesPattern('/Cleanup failure must not erase the primary execution exception/i', $content);
    }

    public function testRfc0006BridgeDependencyDirectionIsUnambiguous(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0006-evolve-bridge-and-incremental-modernisation.md');

        $this->assertMatchesPattern('/`bridge-contracts` depends on `evolvephp\/contracts`/i', $content);
        $this->assertMatchesPattern('/`bridge-contracts` must not depend on Core or HTTP implementations/i', $content);
        $this->assertMatchesPattern('/Adapter packages may depend directly on selected public Evolve packages where required/i', $content);
        $this->assertMatchesPattern('/Adapter packages do not automatically depend on every Evolve package shown/i', $content);
        $this->assertMatchesPattern('/Core and HTTP never depend on host-specific Bridge adapters/i', $content);
        $this->assertMatchesPattern('/Generic Bridge contracts contain no Laravel or Symfony types/i', $content);
        $this->assertMatchesPattern('/RFC 0002.s inward dependency direction remains authoritative/i', $content);
        $this->assertMatchesPattern('/RFC 0006 refines low-level Bridge edges without reversing RFC 0002/i', $content);
        $this->assertDoesNotMatchPattern('/Evolve contracts\/core\/http\s*\R\s*\s*\^\s*\R\s*bridge-contracts/i', $content);
    }

    public function testAuthoritativeGovernanceResponsibilitiesRemainCrossReferenced(): void
    {
        $rfc1 = $this->readProjectFile('docs/rfcs/0001-evolvephp-2-vision-and-scope.md');
        $rfc2 = $this->readProjectFile('docs/rfcs/0002-terminology-package-boundaries-and-public-contracts.md');
        $rfc4 = $this->readProjectFile('docs/rfcs/0004-module-and-plugin-lifecycle.md');
        $rfc5 = $this->readProjectFile('docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md');
        $rfc6 = $this->readProjectFile('docs/rfcs/0006-evolve-bridge-and-incremental-modernisation.md');

        $this->assertMatchesPattern('/RFC 0001 is authoritative for product scope and positioning/i', $rfc1);
        $this->assertMatchesPattern('/RFC 0002 is authoritative for package and public API boundaries/i', $rfc2);
        $this->assertMatchesPattern('/RFC 0004 is authoritative for module and plugin lifecycle behaviour/i', $rfc4);
        $this->assertMatchesPattern('/RFC 0005 is authoritative for execution scope, reset and persistent-worker safety/i', $rfc5);
        $this->assertMatchesPattern('/RFC 0006 is authoritative for Evolve Bridge and incremental-modernisation architecture/i', $rfc6);
    }

    public function testRfcsDoNotClaimTheRepairedArchitectureIsImplemented(): void
    {
        foreach ($this->rfcPaths() as $path) {
            $content = $this->readProjectFile($path);

            $this->assertMatchesPattern('/does not (?:claim|implement|create|publish|promise)|is not implemented|future policy|direction/i', $content);
            $this->assertDoesNotMatchPattern('/\b(?:is|are) already implemented\b/i', $content);
        }
    }

    public function testChangelogRecordsConsistencyRepair(): void
    {
        $content = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/Cross-RFC terminology harmonization/i', $content);
        $this->assertMatchesPattern('/Bridge dependency-direction clarification/i', $content);
        $this->assertMatchesPattern('/execution-scoped tenant-context clarification/i', $content);
        $this->assertMatchesPattern('/telemetry finalization and scope-closure ordering clarification/i', $content);
    }

    private function rfcPaths()
    {
        return array(
            'docs/rfcs/0001-evolvephp-2-vision-and-scope.md',
            'docs/rfcs/0002-terminology-package-boundaries-and-public-contracts.md',
            'docs/rfcs/0003-php-versioning-compatibility-and-release-policy.md',
            'docs/rfcs/0004-module-and-plugin-lifecycle.md',
            'docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md',
            'docs/rfcs/0006-evolve-bridge-and-incremental-modernisation.md',
        );
    }

    private function readProjectFile($path)
    {
        $fullPath = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $this->assertFileExists($fullPath, $path . ' should exist before it is read.');

        return file_get_contents($fullPath);
    }

    private function extractSection($content, $heading)
    {
        $pattern = '/^##\s+[0-9]+\. ' . preg_quote($heading, '/') . '\R(?P<section>.*?)(?=^##\s+[0-9]+\. |\z)/ms';

        $this->assertSame(1, preg_match($pattern, $content, $matches), 'Section not found: ' . $heading);

        return $matches['section'];
    }

    private function assertMatchesPattern($pattern, $content)
    {
        $this->assertSame(1, preg_match($pattern, $content), 'Failed asserting that content matches ' . $pattern);
    }

    private function assertDoesNotMatchPattern($pattern, $content)
    {
        $this->assertSame(0, preg_match($pattern, $content), 'Failed asserting that content does not match ' . $pattern);
    }
}
