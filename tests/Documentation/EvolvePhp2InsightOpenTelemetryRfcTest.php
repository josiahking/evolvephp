<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2InsightOpenTelemetryRfcTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRfc0007ExistsWithAcceptedMetadata(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0007-insight-and-opentelemetry-architecture.md');

        $this->assertMatchesPattern('/#\s*RFC\s+0007:\s*Insight and OpenTelemetry Architecture/i', $content);
        $this->assertMatchesPattern('/Status:\s*Accepted/i', $content);
        $this->assertMatchesPattern('/Target release:\s*EvolvePHP 2\.0 Beta/i', $content);
        $this->assertMatchesPattern('/Decision type:\s*Diagnostics, instrumentation and production telemetry architecture/i', $content);
        $this->assertMatchesPattern('/Depends on:\s*RFC 0001,\s*RFC 0002,\s*RFC 0003,\s*RFC 0004,\s*RFC 0005,\s*RFC 0006/i', $content);
    }

    public function testProductAreaSeparationAndPackageBoundariesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0007-insight-and-opentelemetry-architecture.md');

        $this->assertMatchesPattern('/generic framework instrumentation.*Evolve Insight.*local and development diagnostics.*Evolve Observe.*production OpenTelemetry integration/is', $content);
        $this->assertMatchesPattern('/Core operates without Insight/i', $content);
        $this->assertMatchesPattern('/Core operates without Observe/i', $content);
        $this->assertMatchesPattern('/Insight operates without Observe/i', $content);
        $this->assertMatchesPattern('/Observe operates without Insight/i', $content);
        $this->assertMatchesPattern('/Core does not depend on Insight/i', $content);
        $this->assertMatchesPattern('/Core does not depend on Observe/i', $content);
        $this->assertMatchesPattern('/Insight does not depend on Observe/i', $content);
        $this->assertMatchesPattern('/Observe does not depend on Insight/i', $content);
        $this->assertMatchesPattern('/Observe may depend on selected OpenTelemetry packages/i', $content);
    }

    public function testGenericInstrumentationBoundaryAndEventModelAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0007-insight-and-opentelemetry-architecture.md');

        $this->assertMatchesPattern('/Generic instrumentation must not expose OpenTelemetry SDK classes/i', $content);
        $this->assertMatchesPattern('/Instrumentation must not become a general event bus/i', $content);
        $this->assertMatchesPattern('/Instrumentation consumers must not modify lifecycle ordering/i', $content);
        $this->assertMatchesPattern('/Instrumentation hooks must not carry business-command responsibility/i', $content);
        $this->assertMatchesPattern('/Observation data must be immutable or frozen before asynchronous export/i', $content);
        $this->assertMatchesPattern('/Raw mutable request objects must not be observation payloads/i', $content);
    }

    public function testInsightArchitectureEnvironmentAndStorageRulesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0007-insight-and-opentelemetry-architecture.md');

        $this->assertMatchesPattern('/Insight consumes generic instrumentation/i', $content);
        $this->assertMatchesPattern('/one bounded diagnostic batch per execution/i', $content);
        $this->assertMatchesPattern('/Each batch has exactly one execution identifier/i', $content);
        $this->assertMatchesPattern('/A batch must not merge unrelated executions/i', $content);
        $this->assertMatchesPattern('/Batch size must be bounded/i', $content);
        $this->assertMatchesPattern('/Insight is disabled in production unless explicitly configured/i', $content);
        $this->assertMatchesPattern('/Insight must support complete disablement/i', $content);
        $this->assertMatchesPattern('/Maximum storage size must be bounded/i', $content);
        $this->assertMatchesPattern('/Storage must support redaction before persistence/i', $content);
    }

    public function testObserveOpenTelemetryAndSignalsAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0007-insight-and-opentelemetry-architecture.md');

        $this->assertMatchesPattern('/Observe adapts generic instrumentation.*OpenTelemetry-compatible concepts/is', $content);
        $this->assertMatchesPattern('/Observe is optional/i', $content);
        $this->assertMatchesPattern('/Observe is vendor-neutral by design|Vendor-neutral by design/i', $content);
        $this->assertMatchesPattern('/Observe must not become a telemetry database/i', $content);
        $this->assertMatchesPattern('/Observe must not become a proprietary backend requirement/i', $content);
        $this->assertMatchesPattern('/Traces, metrics and logs/i', $content);
        $this->assertMatchesPattern('/Official compliance claims require implementation and conformance evidence/i', $content);
    }

    public function testLifecycleCorrelationAndContextRulesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0007-insight-and-opentelemetry-architecture.md');

        $this->assertMatchesPattern('/Active telemetry context must end and detach before execution-scope closure/i', $content);
        $this->assertMatchesPattern('/Post-closure persistence or export may use detached immutable data only/i', $content);
        $this->assertMatchesPattern('/Post-closure work must not reactivate a closed execution context/i', $content);
        $this->assertMatchesPattern('/The Evolve execution identifier is not automatically the trace ID/i', $content);
        $this->assertMatchesPattern('/Context must be immutable or behave immutably/i', $content);
        $this->assertMatchesPattern('/Context from one execution must not become the default for another/i', $content);
        $this->assertMatchesPattern('/Context implementation must not rely on uncontrolled global mutable state/i', $content);
    }

    public function testPropagationAndBaggagePoliciesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0007-insight-and-opentelemetry-architecture.md');

        $this->assertMatchesPattern('/W3C Trace Context and W3C Baggage are the initial interoperability direction/i', $content);
        $this->assertMatchesPattern('/Carriers are treated as untrusted input/i', $content);
        $this->assertMatchesPattern('/Baggage is untrusted propagated context/i', $content);
        $this->assertMatchesPattern('/Baggage is not authorization data/i', $content);
        $this->assertMatchesPattern('/Baggage must not automatically become span attributes/i', $content);
        $this->assertMatchesPattern('/Baggage must not automatically become log fields/i', $content);
        $this->assertMatchesPattern('/Baggage must not automatically become metric attributes/i', $content);
        $this->assertMatchesPattern('/Baggage count and total size must be bounded/i', $content);
    }

    public function testSemanticConventionsResourceIdentityAndCardinalityAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0007-insight-and-opentelemetry-architecture.md');

        $this->assertMatchesPattern('/Resource identity is not user identity/i', $content);
        $this->assertMatchesPattern('/Semantic-convention policy/i', $content);
        $this->assertMatchesPattern('/Prefer applicable stable OpenTelemetry semantic conventions/i', $content);
        $this->assertMatchesPattern('/Custom Evolve conventions/i', $content);
        $this->assertMatchesPattern('/Evolve attributes must not use the reserved `?otel\.\*`? namespace/i', $content);
        $this->assertMatchesPattern('/Use one documented Evolve-owned attribute namespace/i', $content);
        $this->assertMatchesPattern('/Every metric attribute requires cardinality review/i', $content);
        $this->assertMatchesPattern('/Metrics must not use execution ID as a dimension/i', $content);
        $this->assertMatchesPattern('/Metrics must not use trace ID or span ID as dimensions/i', $content);
        $this->assertMatchesPattern('/Metrics must not use user ID as a dimension/i', $content);
        $this->assertMatchesPattern('/Metrics must not use tenant ID as a dimension by default/i', $content);
        $this->assertMatchesPattern('/Metrics must not use raw URL as a dimension/i', $content);
        $this->assertMatchesPattern('/Metrics must not use exception messages as dimensions/i', $content);
    }

    public function testSensitiveDataSamplingBufferingCollectorAndOtlpRulesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0007-insight-and-opentelemetry-architecture.md');

        $this->assertMatchesPattern('/Authentication headers/i', $content);
        $this->assertMatchesPattern('/SQL bindings are excluded by default/i', $content);
        $this->assertMatchesPattern('/Request and response bodies are excluded by default/i', $content);
        $this->assertMatchesPattern('/Sampling must not alter authorization/i', $content);
        $this->assertMatchesPattern('/Sampling must not alter audit obligations/i', $content);
        $this->assertMatchesPattern('/In-process telemetry queues must be bounded/i', $content);
        $this->assertMatchesPattern('/Export retries must be bounded/i', $content);
        $this->assertMatchesPattern('/Shutdown flush must be bounded|Shutdown flush must have a deadline/i', $content);
        $this->assertMatchesPattern('/OpenTelemetry Collector is an optional external integration boundary/i', $content);
        $this->assertMatchesPattern('/OTLP is the preferred vendor-neutral export direction/i', $content);
    }

    public function testFailureIsolationRuntimeBridgeDuplicateInstrumentationAndPersistentWorkerRulesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0007-insight-and-opentelemetry-architecture.md');

        $this->assertMatchesPattern('/Exporter failure must not normally fail the execution/i', $content);
        $this->assertMatchesPattern('/Failure to detach active telemetry context is a runtime-safety failure/i', $content);
        $this->assertMatchesPattern('/Such isolation failure triggers RFC 0005 quarantine/i', $content);
        $this->assertMatchesPattern('/Runtime adapters own:/i', $content);
        $this->assertMatchesPattern('/Bridge responsibilities/i', $content);
        $this->assertMatchesPattern('/Remote Bridge must:.*Extract validated incoming propagation.*Inject supported outgoing propagation/is', $content);
        $this->assertMatchesPattern('/Duplicate instrumentation prevention/i', $content);
        $this->assertMatchesPattern('/same operation should not be represented by duplicate framework spans/i', $content);
        $this->assertMatchesPattern('/Future implementation tests must prove:.*New context per execution.*No baggage inherited.*Telemetry-detachment failure quarantines the process/is', $content);
    }

    public function testSecurityPerformanceTestingConsequencesAlternativesAndGovernanceAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0007-insight-and-opentelemetry-architecture.md');

        $this->assertMatchesPattern('/Data classification/i', $content);
        $this->assertMatchesPattern('/Redaction must occur before data leaves its permitted boundary/i', $content);
        $this->assertMatchesPattern('/Security considerations/i', $content);
        $this->assertMatchesPattern('/Performance requirements/i', $content);
        $this->assertMatchesPattern('/Testing requirements/i', $content);
        $this->assertMatchesPattern('/Support and compliance claims/i', $content);
        $this->assertMatchesPattern('/Consequences and tradeoffs/i', $content);
        $this->assertMatchesPattern('/Alternatives considered/i', $content);
        $this->assertMatchesPattern('/RFC 0007 is authoritative for Insight, generic instrumentation and OpenTelemetry architecture/i', $content);
        $this->assertMatchesPattern('/OpenTelemetry support claims require evidence/i', $content);
    }

    public function testRfcIndexAndChangelogReferenceRfc0007(): void
    {
        $index = $this->readProjectFile('docs/rfcs/README.md');
        $changelog = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/0007-insight-and-opentelemetry-architecture\.md/i', $index);
        $this->assertMatchesPattern('/RFC 0007: Insight and OpenTelemetry Architecture.*Accepted/i', $index);
        $this->assertMatchesPattern('/RFC 0007 defines Insight, generic instrumentation and OpenTelemetry architecture/i', $index);
        $this->assertMatchesPattern('/RFC 0007/i', $changelog);
        $this->assertMatchesPattern('/Insight and OpenTelemetry Architecture/i', $changelog);
    }

    public function testCrossRfcConsistencyPoliciesRemainPresent(): void
    {
        $rfc5 = $this->readProjectFile('docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md');
        $rfc6 = $this->readProjectFile('docs/rfcs/0006-evolve-bridge-and-incremental-modernisation.md');
        $consistencyTest = $this->readProjectFile('tests/Documentation/EvolvePhp2RfcConsistencyTest.php');

        $this->assertMatchesPattern('/Active trace, span and propagation context must not survive execution-scope closure/i', $rfc5);
        $this->assertMatchesPattern('/Post-closure export or flush must operate only on detached data/i', $rfc5);
        $this->assertMatchesPattern('/Failure to detach or clear active telemetry context prevents safe worker reuse/i', $rfc5);
        $this->assertMatchesPattern('/Bridge must work without optional observability packages/i', $rfc6);
        $this->assertMatchesPattern('/Remote trace propagation must be validated/i', $rfc6);
        $this->assertMatchesPattern('/testRfc0005OrdersTelemetryFinalizationBeforeScopeClosureAndDetachedExportAfterClosure/i', $consistencyTest);
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
