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
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
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
            resource: ResourceInfoFactory::emptyResource(),
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

    public function testEnabledConfigurationPreservesExactOptionalIdentities(): void
    {
        $tracerProvider = new NoopTracerProvider();
        $meterProvider = new NoopMeterProvider();
        $loggerProvider = NoopLoggerProvider::getInstance();
        $resource = ResourceInfoFactory::emptyResource();
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

        $composition = (new OpenTelemetryCompositionFactory())->create(
            configuration: new ObserveConfiguration(enabled: true),
            tracerProvider: $tracerProvider,
        );

        $this->assertTrue($composition->isEnabled());
        $this->assertSame($tracerProvider, $composition->tracerProvider());
        $this->assertNull($composition->meterProvider());
        $this->assertNull($composition->loggerProvider());
        $this->assertNull($composition->resource());
        $this->assertNull($composition->sampler());
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
}
