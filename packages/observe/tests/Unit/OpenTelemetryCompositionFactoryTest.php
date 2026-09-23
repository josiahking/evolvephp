<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit;

use Evolve\Observe\ObserveConfiguration;
use Evolve\Observe\OpenTelemetryComposition;
use Evolve\Observe\OpenTelemetryCompositionFactory;
use InvalidArgumentException;
use OpenTelemetry\API\Logs\NoopLoggerProvider;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use TypeError;

final class OpenTelemetryCompositionFactoryTest extends TestCase
{
    public function testFactoryIsFinalAndStateless(): void
    {
        $reflection = new ReflectionClass(OpenTelemetryCompositionFactory::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertSame([], $reflection->getProperties());
    }

    public function testDisabledConfigurationReturnsCanonicalDisabledCompositionAndIgnoresSuppliedObjects(): void
    {
        $composition = (new OpenTelemetryCompositionFactory())->create(
            configuration: new ObserveConfiguration(enabled: false),
            tracerProvider: new NoopTracerProvider(),
            meterProvider: new NoopMeterProvider(),
            loggerProvider: NoopLoggerProvider::getInstance(),
            resource: $this->resourceWithServiceName('ignored-service'),
            sampler: new AlwaysOnSampler(),
        );

        $this->assertSame(OpenTelemetryComposition::disabled(), $composition);
        $this->assertFalse($composition->isEnabled());
        $this->assertNull($composition->tracerProvider());
        $this->assertNull($composition->meterProvider());
        $this->assertNull($composition->loggerProvider());
        $this->assertNull($composition->resource());
        $this->assertNull($composition->sampler());
    }

    public function testEnabledConfigurationRequiresTracerProviderWithoutFallback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enabled Observe composition requires a tracer provider.');

        (new OpenTelemetryCompositionFactory())->create(new ObserveConfiguration(enabled: true));
    }

    public function testEnabledConfigurationRequiresResourceWithoutFallback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enabled Observe composition requires an explicit OpenTelemetry resource.');

        (new OpenTelemetryCompositionFactory())->create(
            configuration: new ObserveConfiguration(enabled: true),
            tracerProvider: new NoopTracerProvider(),
        );
    }

    public function testEnabledConfigurationPreservesExactOptionalIdentities(): void
    {
        $tracerProvider = new NoopTracerProvider();
        $meterProvider = new NoopMeterProvider();
        $loggerProvider = NoopLoggerProvider::getInstance();
        $resource = $this->resourceWithServiceName('observe-test');
        $sampler = new AlwaysOnSampler();

        $composition = (new OpenTelemetryCompositionFactory())->create(
            configuration: new ObserveConfiguration(enabled: true),
            tracerProvider: $tracerProvider,
            meterProvider: $meterProvider,
            loggerProvider: $loggerProvider,
            resource: $resource,
            sampler: $sampler,
        );

        $this->assertTrue($composition->isEnabled());
        $this->assertSame($tracerProvider, $composition->tracerProvider());
        $this->assertSame($meterProvider, $composition->meterProvider());
        $this->assertSame($loggerProvider, $composition->loggerProvider());
        $this->assertSame($resource, $composition->resource());
        $this->assertSame($sampler, $composition->sampler());
    }

    public function testEnabledConfigurationDoesNotCreateSilentFallbacksForOptionalProviders(): void
    {
        $tracerProvider = new NoopTracerProvider();
        $resource = $this->resourceWithServiceName('observe-test');

        $composition = (new OpenTelemetryCompositionFactory())->create(
            configuration: new ObserveConfiguration(enabled: true),
            tracerProvider: $tracerProvider,
            resource: $resource,
        );

        $this->assertTrue($composition->isEnabled());
        $this->assertSame($tracerProvider, $composition->tracerProvider());
        $this->assertNull($composition->meterProvider());
        $this->assertNull($composition->loggerProvider());
        $this->assertSame($resource, $composition->resource());
        $this->assertNull($composition->sampler());
    }

    public function testEnabledConfigurationRejectsInvalidResourceServiceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enabled Observe composition resource service.name must be non-empty, unpadded and at most 255 bytes.');

        (new OpenTelemetryCompositionFactory())->create(
            configuration: new ObserveConfiguration(enabled: true),
            tracerProvider: new NoopTracerProvider(),
            resource: ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => ' observe'])),
        );
    }

    public function testSdkSpecificFactoryValuesRejectUnrelatedObjectsThroughPhpTypes(): void
    {
        $this->expectException(TypeError::class);

        (new ReflectionClass(OpenTelemetryCompositionFactory::class))
            ->getMethod('create')
            ->invokeArgs(
                new OpenTelemetryCompositionFactory(),
                [
                    new ObserveConfiguration(enabled: true),
                    new NoopTracerProvider(),
                    null,
                    null,
                    new stdClass(),
                ],
            );
    }

    private function resourceWithServiceName(string $serviceName): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => $serviceName]));
    }
}
