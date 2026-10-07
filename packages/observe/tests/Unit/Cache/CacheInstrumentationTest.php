<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Cache;

use Evolve\Observe\Cache\CacheInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class CacheInstrumentationTest extends TestCase
{
    public function testDisabledPreservesReturnedIterableAndDoesNotInspectKeys(): void
    {
        $keys = (static function (): \Generator {
            yield 'private-key';
        })();
        $values = (static function (): \Generator {
            yield 'private-key' => 'secret';
        })();
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('getMultiple')->with(self::identicalTo($keys), self::identicalTo('default'))->willReturn($values);
        $decorator = new CacheInstrumentation(OpenTelemetryComposition::disabled(), $cache);
        self::assertSame($values, $decorator->getMultiple($keys, 'default'));
    }

    public function testAllEightOperationsDelegateOnce(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        foreach (['get', 'set', 'delete', 'clear', 'getMultiple', 'setMultiple', 'deleteMultiple', 'has'] as $method) {
            $cache->expects(self::once())->method($method);
        }
        $decorator = new CacheInstrumentation(OpenTelemetryComposition::disabled(), $cache);
        $decorator->get('private');
        $decorator->set('private', 'secret');
        $decorator->delete('private');
        $decorator->clear();
        $decorator->getMultiple([]);
        $decorator->setMultiple([]);
        $decorator->deleteMultiple([]);
        $decorator->has('private');
    }
    public function testEnabledCacheRecordsClosedMetricsWithoutKeysOrValues(): void
    {
        $exporter = new \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter();
        $meters = new \Evolve\Observe\Tests\Unit\RecordingMeterProvider();
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'cache-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new \OpenTelemetry\SDK\Trace\TracerProvider(
                new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter),
                resource: $resource,
            ),
            meterProvider: $meters,
            resource: $resource,
        );
        $value = new \stdClass();
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('get')->with('private-key', $value)->willReturn($value);
        $events = [];
        $clock = static function () use (&$events): int {
            $events[] = count($events) === 0 ? 'clock start' : 'clock end';

            return count($events) === 1 ? 1_000_000_000 : 1_200_000_000;
        };
        $decorator = new CacheInstrumentation($composition, $cache, $clock);
        self::assertSame($value, $decorator->get('private-key', $value));
        self::assertSame(['clock start', 'clock end'], $events);
        self::assertCount(1, $exporter->getSpans());
        self::assertSame(\OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL, $exporter->getSpans()[0]->getKind());
        self::assertSame(0.2, $meters->meter->histogram(\Evolve\Observe\EvolveSemanticConventions::METRIC_CACHE_DURATION)->records[0]['amount']);
        self::assertSame(['evolve.cache.operation' => 'get'], $meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_CACHE_COUNT)->records[0]['attributes']);
        self::assertSame([], $meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_CACHE_FAILURES)->records);
        self::assertStringNotContainsString('private-key', json_encode($exporter->getSpans()[0]->getAttributes()->toArray()));
    }

    public function testTelemetrySetupFailureCannotReplaceCacheThrowable(): void
    {
        $failure = new \RuntimeException('private cache failure');
        $provider = $this->createStub(\OpenTelemetry\API\Trace\TracerProviderInterface::class);
        $provider->method('getTracer')->willThrowException(new \RuntimeException('trace failure'));
        $meter = $this->createStub(\OpenTelemetry\API\Metrics\MeterProviderInterface::class);
        $meter->method('getMeter')->willThrowException(new \RuntimeException('meter failure'));
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'cache-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $provider, meterProvider: $meter, resource: $resource);
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('delete')->with('private-key')->willThrowException($failure);
        try {
            (new CacheInstrumentation($composition, $cache, static function (): int {
                throw new \RuntimeException('clock failure');
            }))->delete('private-key');
            self::fail('Expected cache failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testEnabledBulkOperationsPreserveIterableIdentityWithoutTelemetryEnumeration(): void
    {
        $meters = new \Evolve\Observe\Tests\Unit\RecordingMeterProvider();
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'cache-bulk-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(enabled: true, meterProvider: $meters, resource: $resource);
        $iterations = new class {
            public int $count = 0;
        };
        $keys = (static function () use ($iterations): \Generator {
            ++$iterations->count;
            yield 'private-key';
        })();
        $values = (static function () use ($iterations): \Generator {
            ++$iterations->count;
            yield 'private-key' => 'private-value';
        })();
        $result = (static function () use ($iterations): \Generator {
            ++$iterations->count;
            yield 'private-key' => 'private-value';
        })();
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('getMultiple')->with(self::identicalTo($keys))->willReturn($result);
        $cache->expects(self::once())->method('setMultiple')->with(self::identicalTo($values))->willReturn(true);
        $cache->expects(self::once())->method('deleteMultiple')->with(self::identicalTo($keys))->willReturn(true);
        $decorator = new CacheInstrumentation($composition, $cache);
        self::assertSame($result, $decorator->getMultiple($keys));
        self::assertTrue($decorator->setMultiple($values));
        self::assertTrue($decorator->deleteMultiple($keys));
        self::assertSame(0, $iterations->count);
        self::assertSame(
            ['getMultiple', 'setMultiple', 'deleteMultiple'],
            array_column(array_column($meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_CACHE_COUNT)->records, 'attributes'), 'evolve.cache.operation'),
        );
    }

    public function testClockFailureCannotChangeCacheResultOrCount(): void
    {
        $meters = new \Evolve\Observe\Tests\Unit\RecordingMeterProvider();
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'cache-clock-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(enabled: true, meterProvider: $meters, resource: $resource);
        $value = new \stdClass();
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('get')->willReturn($value);
        $instrumented = new CacheInstrumentation(
            $composition,
            $cache,
            static function (): int {
                throw new \RuntimeException('telemetry clock failed');
            },
        );
        self::assertSame($value, $instrumented->get('private-key'));
        self::assertSame([], $meters->meter->histogram(\Evolve\Observe\EvolveSemanticConventions::METRIC_CACHE_DURATION)->records);
        self::assertCount(1, $meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_CACHE_COUNT)->records);
    }

    public function testDisabledPreservesExactApplicationThrowableAndDelegatesOnce(): void
    {
        $failure = new \RuntimeException('application cache failure');
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('delete')->with('private-key')->willThrowException($failure);

        try {
            (new CacheInstrumentation(OpenTelemetryComposition::disabled(), $cache))->delete('private-key');
            self::fail('Expected cache failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }
}
