<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Integration;

use Evolve\Observe\Export\BatchExportConfiguration;
use Evolve\Observe\Export\ExporterFailureTracker;
use Evolve\Observe\Export\OpenTelemetryExportProcessingFactory;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OtlpExportIntegrationTest extends TestCase
{
    public function testRealOtlpTraceExporterComposesWithFactoryBatchProcessorAndFakeTransport(): void
    {
        $transport = new CapturingOtlpTransport();
        $processor = (new OpenTelemetryExportProcessingFactory())->createTraceBatchProcessor(
            new SpanExporter($transport),
            new BatchExportConfiguration(maxQueueSize: 2, maxExportBatchSize: 2, scheduledDelayMillis: 10, autoFlush: false),
            new ExporterFailureTracker(),
        );
        $provider = new TracerProvider($processor, resource: $this->resource());

        $tracer = $provider->getTracer('otlp-test');
        $tracer->spanBuilder('exported-span-1')->startSpan()->end();
        $tracer->spanBuilder('exported-span-2')->startSpan()->end();
        $tracer->spanBuilder('dropped-span-3')->startSpan()->end();

        $this->assertSame(0, $transport->sendCount);

        $this->assertTrue($provider->forceFlush());
        $this->assertSame(1, $transport->sendCount);
        $this->assertSame(ContentTypes::PROTOBUF, $transport->contentType());
        $this->assertNotSame('', $transport->lastPayload);

        $this->assertTrue($provider->forceFlush());
        $this->assertSame(1, $transport->sendCount);
    }

    public function testRealOtlpLogExporterComposesWithFactoryBatchProcessorAndFakeTransport(): void
    {
        $transport = new CapturingOtlpTransport();
        $processor = (new OpenTelemetryExportProcessingFactory())->createLogBatchProcessor(
            new LogsExporter($transport),
            new BatchExportConfiguration(maxQueueSize: 2, maxExportBatchSize: 2, scheduledDelayMillis: 10, autoFlush: false),
            new ExporterFailureTracker(),
        );
        $provider = LoggerProvider::builder()
            ->addLogRecordProcessor($processor)
            ->setResource($this->resource())
            ->build();

        $logger = $provider->getLogger('otlp-test');
        $logger->logRecordBuilder()->setBody('exported-log-1')->emit();
        $logger->logRecordBuilder()->setBody('exported-log-2')->emit();
        $logger->logRecordBuilder()->setBody('dropped-log-3')->emit();

        $this->assertSame(0, $transport->sendCount);

        $this->assertTrue($provider->forceFlush());
        $this->assertSame(1, $transport->sendCount);
        $this->assertSame(ContentTypes::PROTOBUF, $transport->contentType());
        $this->assertNotSame('', $transport->lastPayload);

        $this->assertTrue($provider->forceFlush());
        $this->assertSame(1, $transport->sendCount);
    }

    public function testRealOtlpMetricExporterComposesWithFactoryReaderAndExportsOnExplicitFlush(): void
    {
        $transport = new CapturingOtlpTransport();
        $reader = (new OpenTelemetryExportProcessingFactory())->createMetricExportingReader(
            new MetricExporter($transport, Temporality::CUMULATIVE),
            new ExporterFailureTracker(),
        );
        $provider = MeterProvider::builder()
            ->setResource($this->resource())
            ->addReader($reader)
            ->build();

        $provider->getMeter('otlp-test')->createCounter('test.counter')->add(1);

        $this->assertSame(0, $transport->sendCount);

        $this->assertTrue($provider->forceFlush());
        $this->assertSame(1, $transport->sendCount);
        $this->assertSame(ContentTypes::PROTOBUF, $transport->contentType());
        $this->assertNotSame('', $transport->lastPayload);
    }

    public function testTraceAndLogOtlpTransportFailuresAreTrackedWithoutEscapingApplicationOperations(): void
    {
        $tracker = new ExporterFailureTracker();
        $factory = new OpenTelemetryExportProcessingFactory();

        $traceProvider = new TracerProvider($factory->createTraceBatchProcessor(
            new SpanExporter(new FailingOtlpTransport('secret trace endpoint')),
            new BatchExportConfiguration(maxQueueSize: 8, maxExportBatchSize: 1, scheduledDelayMillis: 10),
            $tracker,
        ), resource: $this->resource());
        $logProvider = LoggerProvider::builder()
            ->addLogRecordProcessor($factory->createLogBatchProcessor(
                new LogsExporter(new FailingOtlpTransport('secret log endpoint')),
                new BatchExportConfiguration(maxQueueSize: 8, maxExportBatchSize: 1, scheduledDelayMillis: 10),
                $tracker,
            ))
            ->setResource($this->resource())
            ->build();

        $this->expectOutputRegex('/OpenTelemetry: \[error\] Export failure.*transport failure/s');

        $traceProvider->getTracer('otlp-test')->spanBuilder('failed-export-span')->startSpan()->end();
        $logProvider->getLogger('otlp-test')->logRecordBuilder()->setBody('failed-export-log')->emit();

        $this->assertTrue($traceProvider->forceFlush());
        $this->assertTrue($logProvider->forceFlush());
        $this->assertSame(1, $tracker->traceFailureCount());
        $this->assertSame('_OTHER', $tracker->traceLastErrorType());
        $this->assertSame(1, $tracker->logFailureCount());
        $this->assertSame('_OTHER', $tracker->logLastErrorType());
        $this->assertStringNotContainsString('secret trace endpoint', json_encode($tracker, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret log endpoint', json_encode($tracker, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('transport failure', json_encode($tracker, JSON_THROW_ON_ERROR));
    }

    private function resource(): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create(['service.name' => 'phase-9-7-otlp-test']));
    }
}

/**
 * @implements TransportInterface<ContentTypes::PROTOBUF>
 */
final class CapturingOtlpTransport implements TransportInterface
{
    public int $sendCount = 0;

    public string $lastPayload = '';

    public function contentType(): string
    {
        return ContentTypes::PROTOBUF;
    }

    /**
     * @return FutureInterface<null>
     */
    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        ++$this->sendCount;
        $this->lastPayload = $payload;

        return new CompletedFuture(null);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}

/**
 * @implements TransportInterface<ContentTypes::PROTOBUF>
 */
final class FailingOtlpTransport implements TransportInterface
{
    public function __construct(private readonly string $secretEndpoint) {}

    public function contentType(): string
    {
        return ContentTypes::PROTOBUF;
    }

    /**
     * @return FutureInterface<null>
     */
    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->secretEndpoint === '') {
            throw new RuntimeException('Missing test endpoint.');
        }

        return new ErrorFuture(new RuntimeException('transport failure'));
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
