<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests;

use Evolve\Benchmarks\Support\ObserveBenchmarkFixtureFactory;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use PHPUnit\Framework\TestCase;

final class ObserveBenchmarkFixtureTest extends TestCase
{
    public function testExecutionModesUseTheSameWorkloadAndOnlyEnabledTracingActivatesContext(): void
    {
        $factory = new ObserveBenchmarkFixtureFactory();
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor($exporter),
            resource: $this->resource(),
        );

        $results = [];

        foreach (['bare', 'disabled', 'enabled'] as $mode) {
            $fixture = $factory->executionFixture($mode, $tracerProvider);
            $results[] = $fixture->invoke();

            $this->assertSame($mode === 'enabled', $fixture->activeDuringOperation());
            $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        }

        $this->assertSame(['ok', 'ok', 'ok'], $results);
        $this->assertCount(1, $exporter->getSpans());

        $enabled = $factory->executionFixture('enabled', $tracerProvider);
        $this->assertSame('ok', $enabled->invoke());
        $this->assertTrue($enabled->activeDuringOperation());
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        $this->assertCount(2, $exporter->getSpans());
    }

    public function testHttpModesUseTheSamePreparedKernelWorkloadAndEnabledTracingCreatesServerSpan(): void
    {
        $factory = new ObserveBenchmarkFixtureFactory();
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor($exporter),
            resource: $this->resource(),
        );

        $statuses = [];

        foreach (['bare', 'disabled', 'enabled'] as $mode) {
            $fixture = $factory->httpFixture($mode, $tracerProvider);
            $statuses[] = $fixture->invoke()->getStatusCode();

            $this->assertSame($mode === 'enabled', $fixture->activeDuringOperation());
            $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        }

        $this->assertSame([200, 200, 200], $statuses);
        $this->assertCount(1, $exporter->getSpans());
        $this->assertSame('GET', $exporter->getSpans()[0]->getName());
    }

    public function testDependentObserveFixturesKeepBareDisabledAndEnabledWorkloadsEquivalent(): void
    {
        $factory = new ObserveBenchmarkFixtureFactory();
        $families = [
            'queueJob' => ['ok', 3, true],
            'database' => [7, 1, false],
            'cache' => ['cached', 1, false],
            'storage' => ['bytes', 3, false],
            'httpClient' => [204, 1, false],
        ];

        foreach ($families as $family => [$expected, $spanCount, $activeWhenEnabled]) {
            $exporter = new InMemoryExporter();
            $provider = new TracerProvider(new SimpleSpanProcessor($exporter), resource: $this->resource());
            $results = [];
            foreach (['bare', 'disabled', 'enabled'] as $mode) {
                $fixture = match ($family) {
                    'queueJob' => $factory->queueJobFixture($mode, $provider),
                    'database' => $factory->databaseFixture($mode, $provider),
                    'cache' => $factory->cacheFixture($mode, $provider),
                    'storage' => $factory->storageFixture($mode, $provider),
                    'httpClient' => $factory->httpClientFixture($mode, $provider),
                };
                $results[] = $fixture->invoke();
                $this->assertSame($mode === 'enabled' && $activeWhenEnabled, $fixture->activeDuringOperation(), $family . ' ' . $mode);
                $this->assertFalse(Span::getCurrent()->getContext()->isValid(), $family . ' ' . $mode);
            }
            $this->assertSame([$expected, $expected, $expected], $results, $family);
            $this->assertCount($spanCount, $exporter->getSpans(), $family);
        }
    }

    public function testObserveBenchmarkContainsAllDependentSurfaceModeVariants(): void
    {
        $benchmarks = new \Evolve\Benchmarks\PhpBench\Observe\ObserveOverheadBench();
        $benchmarks->setUpObserveFixtures();

        foreach (['QueueJob', 'Database', 'Cache', 'Storage', 'HttpClient'] as $family) {
            foreach (['Bare', 'Disabled', 'Enabled'] as $mode) {
                $method = 'bench' . $family . 'Boundary' . $mode;
                $this->assertTrue(method_exists($benchmarks, $method), $method);
            }
        }
    }
    private function resource(): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create([
            ServiceAttributes::SERVICE_NAME => 'observe-benchmark-fixture-test',
        ]));
    }
}
