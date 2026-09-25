<?php

declare(strict_types=1);

namespace Evolve\Observe\Export;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\AggregationInterface;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\DefaultAggregationProviderInterface;
use OpenTelemetry\SDK\Metrics\DefaultAggregationProviderTrait;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use Throwable;

final class OpenTelemetryExportProcessingFactory
{
    public function createTraceBatchProcessor(
        SpanExporterInterface $exporter,
        BatchExportConfiguration $configuration,
        ExporterFailureTracker $failureTracker,
        ?MeterProviderInterface $selfTelemetryMeterProvider = null,
    ): BatchSpanProcessor {
        return new BatchSpanProcessor(
            $this->failureTrackingSpanExporter($exporter, $failureTracker),
            Clock::getDefault(),
            $configuration->maxQueueSize(),
            $configuration->scheduledDelayMillis(),
            BatchSpanProcessor::DEFAULT_EXPORT_TIMEOUT,
            $configuration->maxExportBatchSize(),
            $configuration->autoFlush(),
            $selfTelemetryMeterProvider,
        );
    }

    public function createLogBatchProcessor(
        LogRecordExporterInterface $exporter,
        BatchExportConfiguration $configuration,
        ExporterFailureTracker $failureTracker,
        ?MeterProviderInterface $selfTelemetryMeterProvider = null,
    ): BatchLogRecordProcessor {
        return new BatchLogRecordProcessor(
            $this->failureTrackingLogRecordExporter($exporter, $failureTracker),
            Clock::getDefault(),
            $configuration->maxQueueSize(),
            $configuration->scheduledDelayMillis(),
            BatchLogRecordProcessor::DEFAULT_EXPORT_TIMEOUT,
            $configuration->maxExportBatchSize(),
            $configuration->autoFlush(),
            $selfTelemetryMeterProvider,
        );
    }

    public function createMetricExportingReader(
        PushMetricExporterInterface&AggregationTemporalitySelectorInterface $exporter,
        ExporterFailureTracker $failureTracker,
        ?MeterProviderInterface $selfTelemetryMeterProvider = null,
    ): ExportingReader {
        return new ExportingReader(
            $this->failureTrackingMetricExporter($exporter, $failureTracker),
            $selfTelemetryMeterProvider,
        );
    }

    /**
     * @return SpanExporterInterface
     */
    private function failureTrackingSpanExporter(
        SpanExporterInterface $inner,
        ExporterFailureTracker $failureTracker,
    ): SpanExporterInterface {
        return new class ($inner, $failureTracker) implements SpanExporterInterface {
            public function __construct(private SpanExporterInterface $inner, private ExporterFailureTracker $failureTracker) {}

            /**
             * @return FutureInterface<bool>
             */
            public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
            {
                try {
                    return $this->inner
                        ->export($batch, $cancellation)
                        ->map(function (bool $result): bool {
                            if (!$result) {
                                $this->failureTracker->recordTraceFailure();
                            }

                            return $result;
                        })
                        ->catch(function (Throwable $throwable): never {
                            $this->failureTracker->recordTraceFailure($throwable);

                            throw $throwable;
                        });
                } catch (Throwable $throwable) {
                    $this->failureTracker->recordTraceFailure($throwable);

                    throw $throwable;
                }
            }

            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return $this->inner->shutdown($cancellation);
            }

            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return $this->inner->forceFlush($cancellation);
            }
        };
    }

    /**
     * @return LogRecordExporterInterface
     */
    private function failureTrackingLogRecordExporter(
        LogRecordExporterInterface $inner,
        ExporterFailureTracker $failureTracker,
    ): LogRecordExporterInterface {
        return new class ($inner, $failureTracker) implements LogRecordExporterInterface {
            public function __construct(private LogRecordExporterInterface $inner, private ExporterFailureTracker $failureTracker) {}

            /**
             * @return FutureInterface<bool>
             */
            public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
            {
                try {
                    return $this->inner
                        ->export($batch, $cancellation)
                        ->map(function (bool $result): bool {
                            if (!$result) {
                                $this->failureTracker->recordLogFailure();
                            }

                            return $result;
                        })
                        ->catch(function (Throwable $throwable): never {
                            $this->failureTracker->recordLogFailure($throwable);

                            throw $throwable;
                        });
                } catch (Throwable $throwable) {
                    $this->failureTracker->recordLogFailure($throwable);

                    throw $throwable;
                }
            }

            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return $this->inner->forceFlush($cancellation);
            }

            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return $this->inner->shutdown($cancellation);
            }
        };
    }

    private function failureTrackingMetricExporter(
        PushMetricExporterInterface&AggregationTemporalitySelectorInterface $inner,
        ExporterFailureTracker $failureTracker,
    ): PushMetricExporterInterface&AggregationTemporalitySelectorInterface&DefaultAggregationProviderInterface {
        return new class ($inner, $failureTracker) implements PushMetricExporterInterface, AggregationTemporalitySelectorInterface, DefaultAggregationProviderInterface {
            use DefaultAggregationProviderTrait {
                defaultAggregation as private defaultSdkAggregation;
            }

            public function __construct(
                private PushMetricExporterInterface&AggregationTemporalitySelectorInterface $inner,
                private ExporterFailureTracker $failureTracker,
            ) {}

            public function export(iterable $batch): bool
            {
                try {
                    $result = $this->inner->export($batch);
                } catch (Throwable $throwable) {
                    $this->failureTracker->recordMetricFailure($throwable);

                    throw $throwable;
                }

                if (!$result) {
                    $this->failureTracker->recordMetricFailure();
                }

                return $result;
            }

            public function shutdown(): bool
            {
                return $this->inner->shutdown();
            }

            public function forceFlush(): bool
            {
                return $this->inner->forceFlush();
            }

            public function temporality(MetricMetadataInterface $metric)
            {
                return $this->inner->temporality($metric);
            }

            /**
             * @param array<array-key, mixed> $advisory
             *
             * @return AggregationInterface<mixed>|null
             */
            public function defaultAggregation($instrumentType, array $advisory = []): ?AggregationInterface
            {
                if ($this->inner instanceof DefaultAggregationProviderInterface) {
                    $defaultAggregation = new \ReflectionMethod($this->inner, 'defaultAggregation');
                    $aggregation = $defaultAggregation->invoke($this->inner, $instrumentType, $advisory);

                    if ($aggregation !== null && !$aggregation instanceof AggregationInterface) {
                        throw new \UnexpectedValueException('Metric exporter default aggregation must return an aggregation or null.');
                    }

                    return $aggregation;
                }

                return $this->defaultSdkAggregation($instrumentType, $advisory);
            }
        };
    }
}
