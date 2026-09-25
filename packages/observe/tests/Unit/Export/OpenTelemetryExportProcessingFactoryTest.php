<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Export;

use Evolve\Observe\Export\BatchExportConfiguration;
use Evolve\Observe\Export\ExporterFailureTracker;
use Evolve\Observe\Export\OpenTelemetryExportProcessingFactory;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\Aggregation\ExplicitBucketHistogramAggregation;
use OpenTelemetry\SDK\Metrics\AggregationInterface;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\DefaultAggregationProviderInterface;
use OpenTelemetry\SDK\Metrics\InstrumentType;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class OpenTelemetryExportProcessingFactoryTest extends TestCase
{
    public function testFactoryIsFinalAndStateless(): void
    {
        $reflection = new ReflectionClass(OpenTelemetryExportProcessingFactory::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertSame([], $reflection->getProperties());
    }

    public function testCreatesRealTraceBatchProcessorWithBoundedConfiguration(): void
    {
        $processor = (new OpenTelemetryExportProcessingFactory())->createTraceBatchProcessor(
            new RecordingSpanExporter(),
            new BatchExportConfiguration(8, 2, 123, false),
            new ExporterFailureTracker(),
        );

        $this->assertSame(8, $this->privateValue($processor, 'maxQueueSize'));
        $this->assertSame(2, $this->privateValue($processor, 'maxExportBatchSize'));
        $this->assertSame(123_000_000, $this->privateValue($processor, 'scheduledDelayNanos'));
        $this->assertFalse($this->privateValue($processor, 'autoFlush'));
    }

    public function testCreatesRealLogBatchProcessorWithBoundedConfigurationAndSelfTelemetryProvider(): void
    {
        $processor = (new OpenTelemetryExportProcessingFactory())->createLogBatchProcessor(
            new RecordingLogExporter(),
            new BatchExportConfiguration(9, 3, 456, true),
            new ExporterFailureTracker(),
            new NoopMeterProvider(),
        );

        $this->assertSame(9, $this->privateValue($processor, 'maxQueueSize'));
        $this->assertSame(3, $this->privateValue($processor, 'maxExportBatchSize'));
        $this->assertSame(456_000_000, $this->privateValue($processor, 'scheduledDelayNanos'));
        $this->assertTrue($this->privateValue($processor, 'autoFlush'));
    }

    public function testCreatesRealMetricExportingReaderAndPreservesTemporalityDelegation(): void
    {
        $exporter = new RecordingMetricExporter(temporality: Temporality::DELTA);
        $reader = (new OpenTelemetryExportProcessingFactory())->createMetricExportingReader(
            $exporter,
            new ExporterFailureTracker(),
            new NoopMeterProvider(),
        );

        $wrappedExporter = $this->privateValue($reader, 'exporter');
        $this->assertSame(Temporality::DELTA, $wrappedExporter->temporality(new RecordingMetricMetadata(Temporality::CUMULATIVE)));
    }

    public function testFactoryDoesNotDeclareNamedFailureTrackingDecorators(): void
    {
        $factory = new OpenTelemetryExportProcessingFactory();

        $factory->createTraceBatchProcessor(new RecordingSpanExporter(), new BatchExportConfiguration(), new ExporterFailureTracker());
        $factory->createLogBatchProcessor(new RecordingLogExporter(), new BatchExportConfiguration(), new ExporterFailureTracker());
        $factory->createMetricExportingReader(new RecordingMetricExporter(), new ExporterFailureTracker());

        $this->assertFalse(class_exists('Evolve\Observe\Export\FailureTrackingSpanExporter', false));
        $this->assertFalse(class_exists('Evolve\Observe\Export\FailureTrackingLogRecordExporter', false));
        $this->assertFalse(class_exists('Evolve\Observe\Export\FailureTrackingMetricExporter', false));
    }

    public function testMetricDefaultAggregationPreservesCallerAdvisoryWhenDelegatingToExporter(): void
    {
        $reader = (new OpenTelemetryExportProcessingFactory())->createMetricExportingReader(
            new AdvisoryAwareMetricExporter(),
            new ExporterFailureTracker(),
        );

        $aggregation = $reader->defaultAggregation(InstrumentType::HISTOGRAM, ['ExplicitBucketBoundaries' => [1, 2, 3]]);

        $this->assertInstanceOf(ExplicitBucketHistogramAggregation::class, $aggregation);
        $this->assertSame([1, 2, 3], $aggregation->boundaries);
    }

    public function testExporterSuccessDoesNotIncrementFailureTracker(): void
    {
        $tracker = new ExporterFailureTracker();

        $processor = (new OpenTelemetryExportProcessingFactory())->createTraceBatchProcessor(
            new RecordingSpanExporter(exportResult: true),
            new BatchExportConfiguration(autoFlush: false),
            $tracker,
        );

        $this->privateValue($processor, 'exporter')->export([])->await();

        $this->assertSame(0, $tracker->traceFailureCount());
        $this->assertNull($tracker->traceLastErrorType());
    }

    public function testTraceFalseResultIncrementsOnlyTraceFailureTracker(): void
    {
        $tracker = new ExporterFailureTracker();

        $processor = (new OpenTelemetryExportProcessingFactory())->createTraceBatchProcessor(
            new RecordingSpanExporter(exportResult: false),
            new BatchExportConfiguration(autoFlush: false),
            $tracker,
        );

        $this->assertFalse($this->privateValue($processor, 'exporter')->export([])->await());
        $this->assertSame(1, $tracker->traceFailureCount());
        $this->assertSame('_OTHER', $tracker->traceLastErrorType());
        $this->assertSame(0, $tracker->metricFailureCount());
        $this->assertSame(0, $tracker->logFailureCount());
    }

    public function testLogThrowableIncrementsOnlyLogFailureTrackerAndStoresOnlyClass(): void
    {
        $tracker = new ExporterFailureTracker();
        $throwable = new RuntimeException('secret payload');

        $processor = (new OpenTelemetryExportProcessingFactory())->createLogBatchProcessor(
            new RecordingLogExporter(exportThrowable: $throwable),
            new BatchExportConfiguration(autoFlush: false),
            $tracker,
        );

        try {
            $this->privateValue($processor, 'exporter')->export([])->await();
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $tracker->logFailureCount());
        $this->assertSame(RuntimeException::class, $tracker->logLastErrorType());
        $this->assertStringNotContainsString('secret payload', json_encode($tracker, JSON_THROW_ON_ERROR));
        $this->assertSame(0, $tracker->traceFailureCount());
        $this->assertSame(0, $tracker->metricFailureCount());
    }

    public function testMetricFalseAndThrowableResultsAreTrackedForMetricsOnly(): void
    {
        $tracker = new ExporterFailureTracker();
        $factory = new OpenTelemetryExportProcessingFactory();

        $falseReader = $factory->createMetricExportingReader(new RecordingMetricExporter(exportResult: false), $tracker);
        $this->assertFalse($this->privateValue($falseReader, 'exporter')->export([]));

        $throwingReader = $factory->createMetricExportingReader(
            new RecordingMetricExporter(exportThrowable: new RuntimeException('metric secret')),
            $tracker,
        );

        try {
            $this->privateValue($throwingReader, 'exporter')->export([]);
        } catch (RuntimeException) {
        }

        $this->assertSame(2, $tracker->metricFailureCount());
        $this->assertSame(RuntimeException::class, $tracker->metricLastErrorType());
        $this->assertSame(0, $tracker->traceFailureCount());
        $this->assertSame(0, $tracker->logFailureCount());
        $this->assertStringNotContainsString('metric secret', json_encode($tracker, JSON_THROW_ON_ERROR));
    }

    public function testNoGlobalEnvironmentRegistrationIsPerformed(): void
    {
        $before = getenv('OTEL_EXPORTER_OTLP_ENDPOINT');

        (new OpenTelemetryExportProcessingFactory())->createTraceBatchProcessor(
            new RecordingSpanExporter(),
            new BatchExportConfiguration(),
            new ExporterFailureTracker(),
        );

        $this->assertSame($before, getenv('OTEL_EXPORTER_OTLP_ENDPOINT'));
    }

    private function privateValue(object $object, string $property): mixed
    {
        $reflection = new ReflectionClass($object);

        do {
            if ($reflection->hasProperty($property)) {
                $propertyReflection = $reflection->getProperty($property);
                return $propertyReflection->getValue($object);
            }

            $reflection = $reflection->getParentClass();
        } while ($reflection !== false);

        $this->fail(sprintf('Property %s was not found on %s.', $property, $object::class));
    }
}

final class RecordingSpanExporter implements SpanExporterInterface
{
    public function __construct(private bool $exportResult = true, private ?RuntimeException $exportThrowable = null) {}

    /**
     * @return FutureInterface<bool>
     */
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->exportThrowable !== null) {
            return new ErrorFuture($this->exportThrowable);
        }

        return new CompletedFuture($this->exportResult);
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

final class RecordingLogExporter implements LogRecordExporterInterface
{
    public function __construct(private bool $exportResult = true, private ?RuntimeException $exportThrowable = null) {}

    /**
     * @return FutureInterface<bool>
     */
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->exportThrowable !== null) {
            return new ErrorFuture($this->exportThrowable);
        }

        return new CompletedFuture($this->exportResult);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}

final class RecordingMetricExporter implements PushMetricExporterInterface, AggregationTemporalitySelectorInterface
{
    public function __construct(
        private bool $exportResult = true,
        private ?RuntimeException $exportThrowable = null,
        private string $temporality = Temporality::CUMULATIVE,
    ) {}

    public function export(iterable $batch): bool
    {
        if ($this->exportThrowable !== null) {
            throw $this->exportThrowable;
        }

        return $this->exportResult;
    }

    public function shutdown(): bool
    {
        return true;
    }

    public function forceFlush(): bool
    {
        return true;
    }

    public function temporality(MetricMetadataInterface $metric): string
    {
        return $this->temporality;
    }
}

final class AdvisoryAwareMetricExporter implements PushMetricExporterInterface, AggregationTemporalitySelectorInterface, DefaultAggregationProviderInterface
{
    public function export(iterable $batch): bool
    {
        return true;
    }

    public function shutdown(): bool
    {
        return true;
    }

    public function forceFlush(): bool
    {
        return true;
    }

    public function temporality(MetricMetadataInterface $metric): string
    {
        return Temporality::CUMULATIVE;
    }

    /**
     * @param array<array-key, mixed> $advisory
     *
     * @return AggregationInterface<mixed>
     */
    public function defaultAggregation($instrumentType, array $advisory = []): AggregationInterface
    {
        return new ExplicitBucketHistogramAggregation($advisory['ExplicitBucketBoundaries'] ?? [13, 21, 34]);
    }
}

final class RecordingMetricMetadata implements MetricMetadataInterface
{
    public function __construct(private string $temporality) {}

    public function instrumentType(): string
    {
        return 'counter';
    }

    public function name(): string
    {
        return 'test.counter';
    }

    public function unit(): ?string
    {
        return null;
    }

    public function description(): ?string
    {
        return null;
    }

    public function temporality(): string
    {
        return $this->temporality;
    }
}
