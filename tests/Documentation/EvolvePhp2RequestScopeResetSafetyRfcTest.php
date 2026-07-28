<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2RequestScopeResetSafetyRfcTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRfc0005ExistsWithAcceptedMetadata(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md');

        $this->assertMatchesPattern('/#\s*RFC\s+0005:\s*Request Scope, Runtime Reset and Persistent-Worker Safety/i', $content);
        $this->assertMatchesPattern('/Status:\s*Accepted/i', $content);
        $this->assertMatchesPattern('/Target release:\s*EvolvePHP 2\.0/i', $content);
        $this->assertMatchesPattern('/Decision type:\s*Execution scope, reset and runtime-safety architecture/i', $content);
        $this->assertMatchesPattern('/Depends on:\s*RFC 0001,\s*RFC 0002,\s*RFC 0003,\s*RFC 0004/i', $content);
    }

    public function testLifetimesExecutionKindsAndIdentifiersAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md');

        $this->assertMatchesPattern('/application boot.*execution 1: open -> handle -> close -> reset.*application shutdown/is', $content);
        $this->assertMatchesPattern('/application\s+execution\s+transient/is', $content);
        $this->assertMatchesPattern('/Application lifetime/i', $content);
        $this->assertMatchesPattern('/Execution scope/i', $content);
        $this->assertMatchesPattern('/Transient service/i', $content);
        $this->assertMatchesPattern('/HTTP request scope is one form of execution scope/i', $content);
        $this->assertMatchesPattern('/Queue message/i', $content);
        $this->assertMatchesPattern('/Scheduled job/i', $content);
        $this->assertMatchesPattern('/CLI command/i', $content);
        $this->assertMatchesPattern('/Every execution receives one unique identifier/i', $content);
    }

    public function testLifetimeDependencyAndContextIsolationRulesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md');

        $this->assertMatchesPattern('/Execution-scoped instances must not survive execution closure/i', $content);
        $this->assertMatchesPattern('/application-scoped -> execution-scoped instance/i', $content);
        $this->assertMatchesPattern('/Direct constructor injection of an execution-scoped service into an application singleton is forbidden/i', $content);
        $this->assertMatchesPattern('/Authentication state belongs to execution scope/i', $content);
        $this->assertMatchesPattern('/Application singletons must not retain current-user objects/i', $content);
        $this->assertMatchesPattern('/tenant context belongs to execution scope/i', $content);
        $this->assertMatchesPattern('/RFC 0005 does not define EvolvePHP multitenancy/i', $content);
        $this->assertMatchesPattern('/Locale and timezone selections derived from an execution belong to execution scope/i', $content);
        $this->assertMatchesPattern('/setlocale\(\).*date_default_timezone_set\(\).*controlled restoration/is', $content);
    }

    public function testStaticGlobalAndSuperglobalPoliciesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md');

        $this->assertMatchesPattern('/Mutable request-specific static properties are forbidden/i', $content);
        $this->assertMatchesPattern('/Mutable request-specific global variables are forbidden/i', $content);
        $this->assertMatchesPattern('/Superglobals must be adapted at the runtime boundary/i', $content);
        $this->assertMatchesPattern('/Application code must not read `?\$_GET`?.*`?\$_POST`?.*`?\$_SESSION`?.*directly/is', $content);
        $this->assertMatchesPattern('/Runtime or Bridge adapters may read PHP superglobals at the outer boundary/i', $content);
        $this->assertMatchesPattern('/translate input into explicit request or execution abstractions/i', $content);
    }

    public function testResetParticipantOrderingAndFailurePoliciesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md');

        $this->assertMatchesPattern('/Application-lifetime services that retain reusable mutable infrastructure state may register as reset participants/i', $content);
        $this->assertMatchesPattern('/Registration is explicit/i', $content);
        $this->assertMatchesPattern('/Duplicate reset identifiers are fatal/i', $content);
        $this->assertMatchesPattern('/Reset ordering is deterministic/i', $content);
        $this->assertMatchesPattern('/Dependents reset before their dependencies/i', $content);
        $this->assertMatchesPattern('/Arbitrary numeric reset priority is not the foundational mechanism/i', $content);
        $this->assertMatchesPattern('/Cleanup and reset must always run through a `?finally`?-equivalent path/i', $content);
        $this->assertMatchesPattern('/handler result or handler exception is the primary execution outcome/i', $content);
        $this->assertMatchesPattern('/Cleanup failure must not erase the primary execution exception/i', $content);
        $this->assertMatchesPattern('/reset failure means the process cannot prove isolation/i', $content);
        $this->assertMatchesPattern('/quarantined worker must not accept another execution/i', $content);
    }

    public function testResourceDatabaseEventLoggingAndTelemetryCleanupAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md');

        $this->assertMatchesPattern('/Open transactions must not survive execution closure/i', $content);
        $this->assertMatchesPattern('/Connection-level session changes must be restored or the connection discarded/i', $content);
        $this->assertMatchesPattern('/Temporary listeners belong to execution scope/i', $content);
        $this->assertMatchesPattern('/Deferred callbacks must not silently survive into the next execution/i', $content);
        $this->assertMatchesPattern('/Current user, tenant or request fields must be removed after each execution/i', $content);
        $this->assertMatchesPattern('/Trace and span context belong to execution scope/i', $content);
        $this->assertMatchesPattern('/Observe must clear active context/i', $content);
        $this->assertMatchesPattern('/Output buffers opened during an execution must close or restore deterministically/i', $content);
        $this->assertMatchesPattern('/Temporary streams and files belong to an explicit scope/i', $content);
    }

    public function testRuntimeBridgeConcurrencyAndPersistentWorkerSafetyAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md');

        $this->assertMatchesPattern('/Sequential execution per worker is the safe baseline/i', $content);
        $this->assertMatchesPattern('/Concurrent execution requires independent execution scopes/i', $content);
        $this->assertMatchesPattern('/Runtime adapters must:.*Open one isolated execution scope.*Trigger reset/is', $content);
        $this->assertMatchesPattern('/Core must not import FrankenPHP APIs/i', $content);
        $this->assertMatchesPattern('/This RFC does not implement or promise a release date for FrankenPHP support/i', $content);
        $this->assertMatchesPattern('/Core must not import RoadRunner APIs/i', $content);
        $this->assertMatchesPattern('/This RFC does not implement or promise a release date for RoadRunner support/i', $content);
        $this->assertMatchesPattern('/Bridge must create an Evolve execution scope/i', $content);
        $this->assertMatchesPattern('/Bridge must not assume host cleanup automatically resets Evolve state/i', $content);
        $this->assertMatchesPattern('/Worker reuse is a privilege earned by successful cleanup/i', $content);
    }

    public function testInsightObserveClaimsMemorySecurityAndTestingAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md');

        $this->assertMatchesPattern('/Each diagnostic batch must have one execution identifier/i', $content);
        $this->assertMatchesPattern('/Insight diagnostic batches must not merge unrelated executions/i', $content);
        $this->assertMatchesPattern('/Context propagation must remain execution-scoped/i', $content);
        $this->assertMatchesPattern('/A safety claim requires evidence including/i', $content);
        $this->assertMatchesPattern('/Repeated sequential executions in one process/i', $content);
        $this->assertMatchesPattern('/Memory must not grow without an understood bound/i', $content);
        $this->assertMatchesPattern('/Cross-user state leakage is a security vulnerability/i', $content);
        $this->assertMatchesPattern('/Cross-tenant state leakage is a security vulnerability/i', $content);
        $this->assertMatchesPattern('/Testing requirements/i', $content);
    }

    public function testNonGoalsAlternativesConsequencesIndexAndChangelogAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md');
        $index = $this->readProjectFile('docs/rfcs/README.md');
        $changelog = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/This RFC does not implement scopes/i', $content);
        $this->assertMatchesPattern('/does not promise persistent-worker support in the first alpha/i', $content);
        $this->assertMatchesPattern('/Consequences and tradeoffs/i', $content);
        $this->assertMatchesPattern('/Alternatives considered/i', $content);
        $this->assertMatchesPattern('/Governance/i', $content);
        $this->assertMatchesPattern('/0005-request-scope-runtime-reset-and-persistent-worker-safety\.md/i', $index);
        $this->assertMatchesPattern('/RFC 0005/i', $index);
        $this->assertMatchesPattern('/RFC 0005 defines per-execution scope, reset and worker reuse/i', $index);
        $this->assertMatchesPattern('/RFC 0006 will define Bridge integration/i', $index);
        $this->assertMatchesPattern('/RFC 0007 will define Insight and telemetry architecture/i', $index);
        $this->assertMatchesPattern('/RFC 0005/i', $changelog);
        $this->assertMatchesPattern('/Request Scope, Runtime Reset and Persistent-Worker Safety/i', $changelog);
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
