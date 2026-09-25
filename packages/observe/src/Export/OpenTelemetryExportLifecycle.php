<?php

declare(strict_types=1);

namespace Evolve\Observe\Export;

use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Throwable;

final class OpenTelemetryExportLifecycle
{
    public function __construct(
        private ?TracerProviderInterface $tracerProvider = null,
        private ?MeterProviderInterface $meterProvider = null,
        private ?LoggerProviderInterface $loggerProvider = null,
    ) {}

    public function forceFlush(): ExportLifecycleResult
    {
        return $this->invoke('forceFlush');
    }

    public function shutdown(): ExportLifecycleResult
    {
        return $this->invoke('shutdown');
    }

    private function invoke(string $method): ExportLifecycleResult
    {
        [$tracesSucceeded, $tracesErrorType] = $this->invokeSignal($this->tracerProvider, $method);
        [$metricsSucceeded, $metricsErrorType] = $this->invokeSignal($this->meterProvider, $method);
        [$logsSucceeded, $logsErrorType] = $this->invokeSignal($this->loggerProvider, $method);

        return new ExportLifecycleResult(
            tracesSucceeded: $tracesSucceeded,
            tracesErrorType: $tracesErrorType,
            metricsSucceeded: $metricsSucceeded,
            metricsErrorType: $metricsErrorType,
            logsSucceeded: $logsSucceeded,
            logsErrorType: $logsErrorType,
        );
    }

    /**
     * @return array{0: bool|null, 1: string|null}
     */
    private function invokeSignal(?object $provider, string $method): array
    {
        if ($provider === null) {
            return [null, null];
        }

        try {
            $result = $provider->$method();
        } catch (Throwable $throwable) {
            return [false, $throwable::class];
        }

        if ($result === false) {
            return [false, '_OTHER'];
        }

        return [true, null];
    }
}
