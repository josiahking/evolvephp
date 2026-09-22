<?php

declare(strict_types=1);

namespace Evolve\Observe;

use InvalidArgumentException;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SamplerInterface;

final readonly class OpenTelemetryComposition
{
    public function __construct(
        private bool $enabled,
        private ?TracerProviderInterface $tracerProvider = null,
        private ?MeterProviderInterface $meterProvider = null,
        private ?LoggerProviderInterface $loggerProvider = null,
        private ?ResourceInfo $resource = null,
        private ?SamplerInterface $sampler = null
    ) {
        if ($enabled && $tracerProvider === null) {
            throw new InvalidArgumentException('Enabled Observe composition requires a tracer provider.');
        }

        if (!$enabled && (
            $tracerProvider !== null
            || $meterProvider !== null
            || $loggerProvider !== null
            || $resource !== null
            || $sampler !== null
        )) {
            throw new InvalidArgumentException('Disabled Observe composition cannot contain OpenTelemetry providers, resources or samplers.');
        }
    }

    public static function disabled(): self
    {
        static $composition = null;

        return $composition ??= new self(enabled: false);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function tracerProvider(): ?TracerProviderInterface
    {
        return $this->tracerProvider;
    }

    public function meterProvider(): ?MeterProviderInterface
    {
        return $this->meterProvider;
    }

    public function loggerProvider(): ?LoggerProviderInterface
    {
        return $this->loggerProvider;
    }

    public function resource(): ?ResourceInfo
    {
        return $this->resource;
    }

    public function sampler(): ?SamplerInterface
    {
        return $this->sampler;
    }
}
