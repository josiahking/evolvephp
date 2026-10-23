<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Integration;

use Evolve\Observe\Mcp\McpOperationInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use PHPUnit\Framework\TestCase;

final class McpOperationInstrumentationTest extends TestCase
{
    public function test_disabled_instrumentation_accepts_only_bounded_schema(): void
    {
        $adapter = new McpOperationInstrumentation(OpenTelemetryComposition::disabled());
        $adapter->record(['kind' => 'invoke', 'transport' => 'http', 'outcome' => 'success', 'duration_ns' => 15]);
        $this->expectException(\InvalidArgumentException::class);
        $adapter->record(['kind' => 'invoke', 'transport' => 'http', 'outcome' => 'success', 'prompt' => 'secret']);
    }
    public function test_enabled_trace_lifecycle_and_fixed_metric_records(): void
    {
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(\OpenTelemetry\SDK\Common\Attribute\Attributes::create([
            \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'mcp-test',
        ]));
        $exporter = new \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter();
        $traces = new \OpenTelemetry\SDK\Trace\TracerProvider(
            new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter),
            resource: $resource,
        );
        $fixed = ['evolve.mcp.kind' => 'invoke', 'evolve.mcp.transport' => 'http', 'evolve.mcp.outcome' => 'failure'];
        $counter = $this->createMock(\OpenTelemetry\API\Metrics\CounterInterface::class);
        $counter->expects(self::once())->method('add')->with(1, $fixed);
        $histogram = $this->createMock(\OpenTelemetry\API\Metrics\HistogramInterface::class);
        $histogram->expects(self::once())->method('record')->with(0.25, $fixed);
        $meter = $this->createMock(\OpenTelemetry\API\Metrics\MeterInterface::class);
        $meter->expects(self::once())->method('createCounter')->with('evolve.mcp.operation.count', '{operation}')->willReturn($counter);
        $meter->expects(self::once())->method('createHistogram')->with('evolve.mcp.operation.duration', 's')->willReturn($histogram);
        $meters = $this->createMock(\OpenTelemetry\API\Metrics\MeterProviderInterface::class);
        $meters->expects(self::once())->method('getMeter')->with('evolvephp/observe')->willReturn($meter);
        (new McpOperationInstrumentation(new OpenTelemetryComposition(true, tracerProvider: $traces, meterProvider: $meters, resource: $resource)))->record([
            'kind' => 'invoke', 'transport' => 'http', 'outcome' => 'failure',
            'capability_hash' => hash('sha256', 'private'), 'duration_ns' => 250_000_000,
            'failure_category' => 'operation',
        ]);
        $spans = $exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('evolve.mcp.operation', $spans[0]->getName());
        self::assertSame('Error', $spans[0]->getStatus()->getCode());
        self::assertSame($fixed + ['evolve.mcp.capability_hash' => hash('sha256', 'private'), 'evolve.mcp.failure_category' => 'operation'], $spans[0]->getAttributes()->toArray());
        $traces->shutdown();
    }

    public function test_failing_trace_export_is_contained_and_does_not_block_metrics(): void
    {
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(\OpenTelemetry\SDK\Common\Attribute\Attributes::create([
            \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'mcp-test',
        ]));
        $traces = $this->createStub(\OpenTelemetry\API\Trace\TracerProviderInterface::class);
        $traces->method('getTracer')->willThrowException(new \RuntimeException('trace failed'));
        $counter = $this->createMock(\OpenTelemetry\API\Metrics\CounterInterface::class);
        $counter->expects(self::once())->method('add')->with(1, [
            'evolve.mcp.kind' => 'invoke', 'evolve.mcp.transport' => 'local', 'evolve.mcp.outcome' => 'success',
        ]);
        $meter = $this->createStub(\OpenTelemetry\API\Metrics\MeterInterface::class);
        $meter->method('createCounter')->willReturn($counter);
        $meters = $this->createStub(\OpenTelemetry\API\Metrics\MeterProviderInterface::class);
        $meters->method('getMeter')->willReturn($meter);
        (new McpOperationInstrumentation(new OpenTelemetryComposition(true, tracerProvider: $traces, meterProvider: $meters, resource: $resource)))->record([
            'kind' => 'invoke', 'transport' => 'local', 'outcome' => 'success',
        ]);
    }

    public function test_enabled_logger_emits_only_bounded_correlation_metadata(): void
    {
        $builder = $this->createMock(\OpenTelemetry\API\Logs\LogRecordBuilderInterface::class);
        $builder->expects(self::once())->method('setEventName')->with('evolve.mcp.operation')->willReturnSelf();
        $builder->expects(self::once())->method('setAttributes')->with([
            'evolve.mcp.kind' => 'invoke',
            'evolve.mcp.transport' => 'http',
            'evolve.mcp.outcome' => 'success',
        ])->willReturnSelf();
        $builder->expects(self::once())->method('setAttribute')->with('evolve.mcp.correlation', 'a1b2')->willReturnSelf();
        $builder->expects(self::once())->method('emit');
        $logger = $this->createMock(\OpenTelemetry\API\Logs\LoggerInterface::class);
        $logger->expects(self::once())->method('logRecordBuilder')->willReturn($builder);
        $provider = $this->createMock(\OpenTelemetry\API\Logs\LoggerProviderInterface::class);
        $provider->expects(self::once())->method('getLogger')->willReturn($logger);
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(\OpenTelemetry\SDK\Common\Attribute\Attributes::create([
            \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'mcp-test',
        ]));
        (new McpOperationInstrumentation(new OpenTelemetryComposition(true, loggerProvider: $provider, resource: $resource)))->record([
            'kind' => 'invoke', 'transport' => 'http', 'outcome' => 'success', 'correlation' => 'a1b2',
        ]);
    }
}
