<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Export;

use Evolve\Observe\Export\OpenTelemetryExportLifecycle;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OpenTelemetryExportLifecycleTest extends TestCase
{
    public function testAllProvidersAbsentRemainAbsent(): void
    {
        $result = (new OpenTelemetryExportLifecycle())->forceFlush();

        $this->assertNull($result->tracesSucceeded());
        $this->assertNull($result->metricsSucceeded());
        $this->assertNull($result->logsSucceeded());
        $this->assertFalse($result->hasFailures());
        $this->assertFalse($result->allSucceeded());
    }

    public function testOneSignalForceFlushCanSucceed(): void
    {
        $traces = new RecordingTraceProvider(forceFlushResult: true);
        $result = (new OpenTelemetryExportLifecycle(tracerProvider: $traces))->forceFlush();

        $this->assertSame(1, $traces->forceFlushCalls);
        $this->assertTrue($result->tracesSucceeded());
        $this->assertNull($result->metricsSucceeded());
        $this->assertNull($result->logsSucceeded());
        $this->assertFalse($result->hasFailures());
        $this->assertTrue($result->allSucceeded());
    }

    public function testAllThreeSignalsShutdownIndependently(): void
    {
        $traces = new RecordingTraceProvider(shutdownResult: true);
        $metrics = new RecordingMeterProvider(shutdownResult: true);
        $logs = new RecordingLoggerProvider(shutdownResult: true);

        $result = (new OpenTelemetryExportLifecycle($traces, $metrics, $logs))->shutdown();

        $this->assertSame(1, $traces->shutdownCalls);
        $this->assertSame(1, $metrics->shutdownCalls);
        $this->assertSame(1, $logs->shutdownCalls);
        $this->assertTrue($result->tracesSucceeded());
        $this->assertTrue($result->metricsSucceeded());
        $this->assertTrue($result->logsSucceeded());
        $this->assertFalse($result->hasFailures());
    }

    public function testFalseResultIsClassifiedAsOtherWithoutStoppingRemainingSignals(): void
    {
        $traces = new RecordingTraceProvider(forceFlushResult: false);
        $metrics = new RecordingMeterProvider(forceFlushResult: true);

        $result = (new OpenTelemetryExportLifecycle($traces, $metrics))->forceFlush();

        $this->assertFalse($result->tracesSucceeded());
        $this->assertSame('_OTHER', $result->tracesErrorType());
        $this->assertTrue($result->metricsSucceeded());
        $this->assertSame(1, $metrics->forceFlushCalls);
        $this->assertTrue($result->hasFailures());
        $this->assertFalse($result->allSucceeded());
    }

    public function testThrownFailureIsClassifiedByClassOnlyWithoutMessageOrStack(): void
    {
        $traces = new RecordingTraceProvider(forceFlushThrowable: new RuntimeException('secret endpoint https://collector.example'));
        $logs = new RecordingLoggerProvider(forceFlushResult: true);

        $result = (new OpenTelemetryExportLifecycle($traces, null, $logs))->forceFlush();

        $this->assertFalse($result->tracesSucceeded());
        $this->assertSame(RuntimeException::class, $result->tracesErrorType());
        $this->assertStringNotContainsString('secret endpoint', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertTrue($result->logsSucceeded());
        $this->assertSame(1, $logs->forceFlushCalls);
    }

    public function testConstructionDoesNotInvokeShutdown(): void
    {
        $traces = new RecordingTraceProvider();

        $lifecycle = new OpenTelemetryExportLifecycle(tracerProvider: $traces);

        $this->assertGreaterThan(0, spl_object_id($lifecycle));
        $this->assertSame(0, $traces->shutdownCalls);
        $this->assertSame(0, $traces->forceFlushCalls);
    }
}

final class RecordingTraceProvider implements TracerProviderInterface
{
    public int $forceFlushCalls = 0;
    public int $shutdownCalls = 0;

    public function __construct(
        private bool $forceFlushResult = true,
        private bool $shutdownResult = true,
        private ?RuntimeException $forceFlushThrowable = null,
        private ?RuntimeException $shutdownThrowable = null,
    ) {}

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        ++$this->forceFlushCalls;

        if ($this->forceFlushThrowable !== null) {
            throw $this->forceFlushThrowable;
        }

        return $this->forceFlushResult;
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        ++$this->shutdownCalls;

        if ($this->shutdownThrowable !== null) {
            throw $this->shutdownThrowable;
        }

        return $this->shutdownResult;
    }

    /**
     * @param iterable<array-key, mixed> $attributes
     */
    public function getTracer(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): TracerInterface
    {
        throw new RuntimeException('not used');
    }

    /**
     * @param Configurator<mixed> $configurator
     */
    public function updateConfigurator(Configurator $configurator): void {}
}

final class RecordingMeterProvider implements MeterProviderInterface
{
    public int $forceFlushCalls = 0;
    public int $shutdownCalls = 0;

    public function __construct(
        private bool $forceFlushResult = true,
        private bool $shutdownResult = true,
        private ?RuntimeException $forceFlushThrowable = null,
        private ?RuntimeException $shutdownThrowable = null,
    ) {}

    public function forceFlush(): bool
    {
        ++$this->forceFlushCalls;

        if ($this->forceFlushThrowable !== null) {
            throw $this->forceFlushThrowable;
        }

        return $this->forceFlushResult;
    }

    public function shutdown(): bool
    {
        ++$this->shutdownCalls;

        if ($this->shutdownThrowable !== null) {
            throw $this->shutdownThrowable;
        }

        return $this->shutdownResult;
    }

    /**
     * @param iterable<array-key, mixed> $attributes
     */
    public function getMeter(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): MeterInterface
    {
        throw new RuntimeException('not used');
    }

    /**
     * @param Configurator<mixed> $configurator
     */
    public function updateConfigurator(Configurator $configurator): void {}
}

final class RecordingLoggerProvider implements LoggerProviderInterface
{
    public int $forceFlushCalls = 0;
    public int $shutdownCalls = 0;

    public function __construct(
        private bool $forceFlushResult = true,
        private bool $shutdownResult = true,
        private ?RuntimeException $forceFlushThrowable = null,
        private ?RuntimeException $shutdownThrowable = null,
    ) {}

    public function forceFlush(): bool
    {
        ++$this->forceFlushCalls;

        if ($this->forceFlushThrowable !== null) {
            throw $this->forceFlushThrowable;
        }

        return $this->forceFlushResult;
    }

    public function shutdown(): bool
    {
        ++$this->shutdownCalls;

        if ($this->shutdownThrowable !== null) {
            throw $this->shutdownThrowable;
        }

        return $this->shutdownResult;
    }

    /**
     * @param iterable<array-key, mixed> $attributes
     */
    public function getLogger(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): LoggerInterface
    {
        throw new RuntimeException('not used');
    }

    /**
     * @param Configurator<mixed> $configurator
     */
    public function updateConfigurator(Configurator $configurator): void {}
}
