<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit;

use Evolve\Observe\OpenTelemetryComposition;
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

final class OpenTelemetryCompositionTest extends TestCase
{
    public function testCompositionIsFinalReadonly(): void
    {
        $reflection = new ReflectionClass(OpenTelemetryComposition::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testDisabledCompositionIsCanonicalAndCarriesNoReferences(): void
    {
        $composition = OpenTelemetryComposition::disabled();

        $this->assertSame($composition, OpenTelemetryComposition::disabled());
        $this->assertFalse($composition->isEnabled());
        $this->assertNull($composition->tracerProvider());
        $this->assertNull($composition->meterProvider());
        $this->assertNull($composition->loggerProvider());
        $this->assertNull($composition->resource());
        $this->assertNull($composition->sampler());
    }

    public function testEnabledCompositionPreservesExactProviderResourceAndSamplerIdentity(): void
    {
        $tracerProvider = new NoopTracerProvider();
        $meterProvider = new NoopMeterProvider();
        $loggerProvider = NoopLoggerProvider::getInstance();
        $resource = ResourceInfoFactory::emptyResource();
        $sampler = new AlwaysOnSampler();

        $composition = new OpenTelemetryComposition(
            enabled: true,
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

    public function testDirectEnabledCompositionRequiresTracerProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enabled Observe composition requires a tracer provider.');

        new OpenTelemetryComposition(enabled: true);
    }

    public function testDirectDisabledCompositionRejectsTracerProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Disabled Observe composition cannot contain OpenTelemetry providers, resources or samplers.');

        new OpenTelemetryComposition(
            enabled: false,
            tracerProvider: new NoopTracerProvider(),
        );
    }

    public function testDirectDisabledCompositionRejectsSdkResource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Disabled Observe composition cannot contain OpenTelemetry providers, resources or samplers.');

        new OpenTelemetryComposition(
            enabled: false,
            resource: ResourceInfoFactory::emptyResource(),
        );
    }

    public function testSdkSpecificCompositionValuesRejectUnrelatedObjectsThroughPhpTypes(): void
    {
        $this->expectException(TypeError::class);

        (new ReflectionClass(OpenTelemetryComposition::class))->newInstanceArgs([
            true,
            new NoopTracerProvider(),
            null,
            null,
            new stdClass(),
        ]);
    }
}
