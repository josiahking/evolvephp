<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit;

use Evolve\Observe\OpenTelemetryComposition;
use InvalidArgumentException;
use OpenTelemetry\API\Logs\NoopLoggerProvider;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $resource = $this->resourceWithServiceName('observe-test');
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

    public function testDirectEnabledCompositionRequiresResource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enabled Observe composition requires an explicit OpenTelemetry resource.');

        new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new NoopTracerProvider(),
        );
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
            resource: $this->resourceWithServiceName('observe-test'),
        );
    }

    public function testDirectEnabledCompositionRejectsMissingServiceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enabled Observe composition requires resource service.name.');

        new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new NoopTracerProvider(),
            resource: ResourceInfo::create(Attributes::create([])),
        );
    }

    public function testDirectEnabledCompositionRejectsNonStringServiceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enabled Observe composition resource service.name must be a string.');

        new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new NoopTracerProvider(),
            resource: ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 123])),
        );
    }

    #[DataProvider('invalidServiceNames')]
    public function testDirectEnabledCompositionRejectsInvalidStringServiceName(string $serviceName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enabled Observe composition resource service.name must be non-empty, unpadded and at most 255 bytes.');

        new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new NoopTracerProvider(),
            resource: $this->resourceWithServiceName($serviceName),
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidServiceNames(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ['   '],
            'leading whitespace' => [' observe'],
            'trailing whitespace' => ['observe '],
            'too long' => [str_repeat('a', 256)],
        ];
    }

    private function resourceWithServiceName(string $serviceName): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => $serviceName]));
    }
}
