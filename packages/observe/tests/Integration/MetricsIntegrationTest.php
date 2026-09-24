<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Integration;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Observe\EvolveSemanticConventions;
use Evolve\Observe\ExecutionMetricsInstrumentation;
use Evolve\Observe\ExecutionTraceInstrumentation;
use Evolve\Observe\Http\HttpServerMetricsInstrumentation;
use Evolve\Observe\Http\HttpServerTraceInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use Evolve\Observe\Tests\Unit\Http\TraceResponse;
use Evolve\Observe\Tests\Unit\Http\TraceServerRequest;
use Evolve\Observe\Tests\Unit\RecordingMeterProvider;
use Evolve\Observe\Tests\Unit\SequenceClock;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class MetricsIntegrationTest extends TestCase
{
    public function testMeterOnlyCompositionRecordsExecutionMetricsWhileTracingIsInert(): void
    {
        $meterProvider = new RecordingMeterProvider();
        $metrics = new ExecutionMetricsInstrumentation(
            new OpenTelemetryComposition(enabled: true, meterProvider: $meterProvider, resource: $this->resource()),
            (new SequenceClock(1_000_000_000, 1_200_000_000))(...),
        );
        $trace = new ExecutionTraceInstrumentation(new OpenTelemetryComposition(
            enabled: true,
            meterProvider: new NoopMeterProvider(),
            resource: $this->resource(),
        ));

        $registry = new ServiceRegistry();
        $registry->freeze();
        $outcome = (new ExecutionOrchestrator($registry, [$metrics, $trace], [$trace]))->execute(
            ExecutionKind::HttpRequest,
            static function (): string {
                self::assertFalse(Span::getCurrent()->getContext()->isValid());

                return 'ok';
            },
        );

        $this->assertTrue($outcome->primarySucceeded());
        $this->assertCount(1, $meterProvider->meter->histogram(EvolveSemanticConventions::METRIC_EXECUTION_DURATION)->records);
        $this->assertSame(0, $meterProvider->meter->upDownCounter(EvolveSemanticConventions::METRIC_EXECUTION_ACTIVE)->netAmount());
    }

    public function testHttpMetricsWrapRealPsrRequestResponseOperationWithClosedDimensions(): void
    {
        $meterProvider = new RecordingMeterProvider();
        $metrics = new HttpServerMetricsInstrumentation(
            new OpenTelemetryComposition(enabled: true, meterProvider: $meterProvider, resource: $this->resource()),
            (new SequenceClock(1_000_000_000, 1_075_000_000))(...),
        );

        $response = $metrics->measure(
            TraceServerRequest::method('custom-method', '/users/123?token=secret'),
            static fn(): ResponseInterface => new TraceResponse(200),
        );

        $this->assertSame(200, $response->getStatusCode());
        $duration = $meterProvider->meter->histogram(HttpMetrics::HTTP_SERVER_REQUEST_DURATION)->records[0];
        $this->assertSame(0.075, $duration['amount']);
        $this->assertSame(
            [HttpAttributes::HTTP_REQUEST_METHOD => HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER],
            $duration['attributes'],
        );
    }

    public function testMetricsAndTracingCoexistWithoutChangingTraceOwnership(): void
    {
        $meterProvider = new RecordingMeterProvider();
        $exporter = new InMemoryExporter();
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new TracerProvider(new SimpleSpanProcessor($exporter), resource: $this->resource()),
            meterProvider: $meterProvider,
            resource: $this->resource(),
        );
        $metrics = new HttpServerMetricsInstrumentation($composition, (new SequenceClock(1_000_000_000, 1_010_000_000))(...));
        $trace = new HttpServerTraceInstrumentation($composition);

        $metrics->measure(
            TraceServerRequest::get('/coexist'),
            static fn($request): ResponseInterface => $trace->trace(
                $request,
                static fn(): ResponseInterface => new TraceResponse(200),
            ),
        );

        $this->assertCount(1, $exporter->getSpans());
        $this->assertCount(1, $meterProvider->meter->counter(EvolveSemanticConventions::METRIC_HTTP_SERVER_REQUEST_COUNT)->records);
    }

    private function resource(): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'observe-metrics-integration-test']));
    }
}
