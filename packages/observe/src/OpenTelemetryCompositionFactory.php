<?php

declare(strict_types=1);

namespace Evolve\Observe;

use InvalidArgumentException;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SamplerInterface;

final class OpenTelemetryCompositionFactory
{
    public function create(
        ObserveConfiguration $configuration,
        ?TracerProviderInterface $tracerProvider = null,
        ?MeterProviderInterface $meterProvider = null,
        ?LoggerProviderInterface $loggerProvider = null,
        ?ResourceInfo $resource = null,
        ?SamplerInterface $sampler = null
    ): OpenTelemetryComposition {
        if (!$configuration->isEnabled()) {
            return OpenTelemetryComposition::disabled();
        }

        if ($tracerProvider === null) {
            throw new InvalidArgumentException('Enabled Observe composition requires a tracer provider.');
        }

        return new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: $tracerProvider,
            meterProvider: $meterProvider,
            loggerProvider: $loggerProvider,
            resource: $resource,
            sampler: $sampler,
        );
    }
}
