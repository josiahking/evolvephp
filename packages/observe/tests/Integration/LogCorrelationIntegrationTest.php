<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Integration;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Observe\ExecutionTraceInstrumentation;
use Evolve\Observe\Logging\ExecutionLogCorrelationInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use PHPUnit\Framework\TestCase;

final class LogCorrelationIntegrationTest extends TestCase
{
    public function testApplicationOwnedOpenTelemetryLoggerUsesNativeSpanContextAndEvolveAttributes(): void
    {
        $resource = $this->resource();
        $spanExporter = new InMemorySpanExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter), resource: $resource);
        $logExporter = new InMemoryLogExporter();
        $loggerProvider = LoggerProvider::builder()
            ->setResource($resource)
            ->addLogRecordProcessor(new SimpleLogRecordProcessor($logExporter))
            ->build();
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: $tracerProvider,
            loggerProvider: $loggerProvider,
            resource: $resource,
        );
        $tracing = new ExecutionTraceInstrumentation($composition);
        $correlation = new ExecutionLogCorrelationInstrumentation($composition);
        $logger = $loggerProvider->getLogger('application-owned');
        $capturedTraceId = null;
        $capturedSpanId = null;
        $capturedExecutionId = null;

        $registry = new ServiceRegistry();
        $registry->freeze();
        $outcome = (new ExecutionOrchestrator($registry, [$tracing], [$correlation, $tracing]))->execute(
            ExecutionKind::HttpRequest,
            static function (ExecutionContext $context) use ($correlation, $logger, &$capturedTraceId, &$capturedSpanId, &$capturedExecutionId): void {
                $snapshot = $correlation->current();
                $capturedTraceId = Span::getCurrent()->getContext()->getTraceId();
                $capturedSpanId = Span::getCurrent()->getContext()->getSpanId();
                $capturedExecutionId = $context->identifier()->value();

                $logger->logRecordBuilder()
                    ->setSeverityNumber(Severity::INFO)
                    ->setBody('handled request')
                    ->setAttributes($snapshot->openTelemetryAttributes())
                    ->emit();
            },
        );

        $this->assertTrue($outcome->primarySucceeded());
        $this->assertCount(1, $spanExporter->getSpans());
        $logStorage = $logExporter->getStorage();

        $this->assertCount(1, $logStorage);

        $record = null;

        foreach ($logStorage as $candidate) {
            if ($candidate instanceof ReadableLogRecord) {
                $record = $candidate;

                break;
            }
        }

        $this->assertInstanceOf(ReadableLogRecord::class, $record);

        $spanContext = $record->getSpanContext();
        $attributes = $record->getAttributes()->toArray();

        $this->assertSame($capturedTraceId, $spanContext->getTraceId());
        $this->assertSame($capturedSpanId, $spanContext->getSpanId());
        $this->assertSame($capturedExecutionId, $attributes['evolve.execution.id']);
        $this->assertSame(ExecutionKind::HttpRequest->value, $attributes['evolve.execution.kind']);
        $this->assertArrayNotHasKey('trace_id', $attributes);
        $this->assertArrayNotHasKey('span_id', $attributes);
        $this->assertArrayNotHasKey('trace_flags', $attributes);
        $this->assertTrue($loggerProvider->forceFlush());
        $this->assertTrue($loggerProvider->shutdown());
    }

    private function resource(): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'observe-log-integration-test']));
    }
}
