<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Storage;

use Evolve\Observe\OpenTelemetryComposition;
use Evolve\Observe\Storage\ObjectStorageInstrumentation;
use Evolve\Storage\Contracts\ObjectStorage;
use Evolve\Storage\Contracts\ReadableObject;
use Evolve\Storage\Contracts\StorageKey;
use PHPUnit\Framework\TestCase;

final class ObjectStorageInstrumentationTest extends TestCase
{
    public function testPutDoesNotEnumerateChunksAndOpenWrapsReader(): void
    {
        $chunks = (static function (): \Generator {
            yield 'secret';
        })();
        $reader = new class implements ReadableObject {
            public function read(int $maxBytes): string
            {
                return 'secret';
            }
            public function close(): void {}
        };
        $storage = new class ($chunks, $reader) implements ObjectStorage {
            public int $puts = 0;
            /** @param iterable<string> $chunks */
            public function __construct(private iterable $chunks, private ReadableObject $reader) {}
            public function put(StorageKey $key, iterable $chunks): void
            {
                \PHPUnit\Framework\Assert::assertSame($this->chunks, $chunks);
                ++$this->puts;
            }
            public function open(StorageKey $key): ReadableObject
            {
                return $this->reader;
            }
            public function delete(StorageKey $key): void {}
        };
        $decorator = new ObjectStorageInstrumentation(OpenTelemetryComposition::disabled(), $storage);
        $key = new StorageKey('private');
        $decorator->put($key, $chunks);
        self::assertSame(1, $storage->puts);
        self::assertSame('secret', $decorator->open($key)->read(8));
    }
    public function testReaderSpansKeepOpenOriginAcrossExecutionContexts(): void
    {
        $exporter = new \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter();
        $meters = new \Evolve\Observe\Tests\Unit\RecordingMeterProvider();
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'storage-test',
            ]),
        );
        $provider = new \OpenTelemetry\SDK\Trace\TracerProvider(
            new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter),
            resource: $resource,
        );
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $provider, meterProvider: $meters, resource: $resource);
        $reader = new class implements ReadableObject {
            public int $reads = 0;
            public int $closes = 0;
            public function read(int $maxBytes): string
            {
                ++$this->reads;
                return 'private bytes';
            }
            public function close(): void
            {
                ++$this->closes;
            }
        };
        $storage = new class ($reader) implements ObjectStorage {
            public function __construct(private ReadableObject $reader) {}
            public function put(StorageKey $key, iterable $chunks): void {}
            public function open(StorageKey $key): ReadableObject
            {
                return $this->reader;
            }
            public function delete(StorageKey $key): void {}
        };
        $decorator = new ObjectStorageInstrumentation($composition, $storage);
        $tracer = $provider->getTracer('storage-test');
        $spanA = $tracer->spanBuilder('execution-a')->startSpan();
        $scopeA = $spanA->activate();
        $opened = $decorator->open(new StorageKey('private-key'));
        $scopeA->detach();
        $spanB = $tracer->spanBuilder('execution-b')->startSpan();
        $scopeB = $spanB->activate();
        self::assertSame('private bytes', $opened->read(32));
        $opened->close();
        $scopeB->detach();
        $spanB->end();
        $spanA->end();

        self::assertSame(1, $reader->reads);
        self::assertSame(1, $reader->closes);
        $infrastructure = array_values(array_filter($exporter->getSpans(), static fn($span): bool => $span->getName() === \Evolve\Observe\EvolveSemanticConventions::SPAN_NAME_STORAGE));
        self::assertCount(3, $infrastructure);
        foreach ($infrastructure as $span) {
            self::assertSame($spanA->getContext()->getSpanId(), $span->getParentSpanId());
            self::assertNotSame($spanB->getContext()->getSpanId(), $span->getParentSpanId());
            self::assertSame(\OpenTelemetry\API\Trace\SpanKind::KIND_INTERNAL, $span->getKind());
            self::assertStringNotContainsString('private-key', json_encode($span->getAttributes()->toArray()));
            self::assertStringNotContainsString('private bytes', json_encode($span->getAttributes()->toArray()));
        }
        self::assertSame(['evolve.storage.operation' => 'read'], $meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_STORAGE_COUNT)->records[1]['attributes']);
    }

    public function testMissingObjectStaysNullAndAllOwningOperationsDelegateOnce(): void
    {
        $storage = new class implements ObjectStorage {
            public int $puts = 0;
            public int $opens = 0;
            public int $deletes = 0;
            public function put(StorageKey $key, iterable $chunks): void
            {
                ++$this->puts;
            }
            public function open(StorageKey $key): ?ReadableObject
            {
                ++$this->opens;
                return null;
            }
            public function delete(StorageKey $key): void
            {
                ++$this->deletes;
            }
        };
        $decorator = new ObjectStorageInstrumentation(OpenTelemetryComposition::disabled(), $storage);
        $key = new StorageKey('private');
        $decorator->put($key, []);
        self::assertNull($decorator->open($key));
        $decorator->delete($key);
        self::assertSame([1, 1, 1], [$storage->puts, $storage->opens, $storage->deletes]);
    }

    public function testReaderFailurePreservesThrowableAndRecordsOnlyBoundedStorageMetadata(): void
    {
        $failure = new class extends \RuntimeException implements \Evolve\Storage\Contracts\Exception\StorageException {
            public function operation(): \Evolve\Storage\Contracts\StorageOperation
            {
                return \Evolve\Storage\Contracts\StorageOperation::Read;
            }

            public function category(): \Evolve\Storage\Contracts\StorageFailureCategory
            {
                return \Evolve\Storage\Contracts\StorageFailureCategory::Transport;
            }
        };
        $exporter = new \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter();
        $meters = new \Evolve\Observe\Tests\Unit\RecordingMeterProvider();
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'storage-failure-test',
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
        $reader = new class ($failure) implements ReadableObject {
            public int $closes = 0;
            public function __construct(private \RuntimeException $failure) {}
            public function read(int $maxBytes): ?string
            {
                throw $this->failure;
            }
            public function close(): void
            {
                ++$this->closes;
            }
        };
        $storage = new class ($reader) implements ObjectStorage {
            public function __construct(private ReadableObject $reader) {}
            public function put(StorageKey $key, iterable $chunks): void {}
            public function open(StorageKey $key): ReadableObject
            {
                return $this->reader;
            }
            public function delete(StorageKey $key): void {}
        };
        $opened = (new ObjectStorageInstrumentation($composition, $storage))->open(new StorageKey('private-key'));
        try {
            $opened->read(8);
            self::fail('Expected storage failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        $opened->close();
        self::assertSame(1, $reader->closes);
        $readSpan = array_values(array_filter($exporter->getSpans(), static fn($span): bool => $span->getAttributes()->get(\Evolve\Observe\EvolveSemanticConventions::ATTRIBUTE_STORAGE_OPERATION) === 'read'))[0];
        self::assertSame('transport', $readSpan->getAttributes()->get(\Evolve\Observe\EvolveSemanticConventions::ATTRIBUTE_STORAGE_FAILURE_CATEGORY));
        self::assertSame(['evolve.storage.operation' => 'read'], $meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_STORAGE_FAILURES)->records[0]['attributes']);
        self::assertStringNotContainsString('private-key', json_encode($readSpan->getAttributes()->toArray()));
    }

    public function testDisabledOpenPreservesReaderIdentityAndOwningThrowable(): void
    {
        $reader = $this->createStub(ReadableObject::class);
        $failure = new \RuntimeException('application storage failure');
        $storage = new class ($reader, $failure) implements ObjectStorage {
            public int $opens = 0;
            public int $deletes = 0;
            public function __construct(private ReadableObject $reader, private \RuntimeException $failure) {}
            public function put(StorageKey $key, iterable $chunks): void {}
            public function open(StorageKey $key): ReadableObject
            {
                ++$this->opens;
                return $this->reader;
            }
            public function delete(StorageKey $key): void
            {
                ++$this->deletes;
                throw $this->failure;
            }
        };
        $decorator = new ObjectStorageInstrumentation(OpenTelemetryComposition::disabled(), $storage);
        $key = new StorageKey('private');
        self::assertSame($reader, $decorator->open($key));
        self::assertSame(1, $storage->opens);
        try {
            $decorator->delete($key);
            self::fail('Expected storage failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(1, $storage->deletes);
    }

    public function testTelemetrySetupFailurePreservesStorageResultAndThrowableWithoutDoubleDelegation(): void
    {
        $traces = $this->createStub(\OpenTelemetry\API\Trace\TracerProviderInterface::class);
        $traces->method('getTracer')->willThrowException(new \RuntimeException('trace setup failure'));
        $meters = $this->createStub(\OpenTelemetry\API\Metrics\MeterProviderInterface::class);
        $meters->method('getMeter')->willThrowException(new \RuntimeException('meter setup failure'));
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'storage-isolation-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $traces, meterProvider: $meters, resource: $resource);
        $failure = new \RuntimeException('application storage failure');
        $reader = $this->createStub(ReadableObject::class);
        $reader->method('read')->willReturn('application bytes');
        $storage = new class ($reader, $failure) implements ObjectStorage {
            public int $opens = 0;
            public int $deletes = 0;
            public function __construct(private ReadableObject $reader, private \RuntimeException $failure) {}
            public function put(StorageKey $key, iterable $chunks): void {}
            public function open(StorageKey $key): ReadableObject
            {
                ++$this->opens;
                return $this->reader;
            }
            public function delete(StorageKey $key): void
            {
                ++$this->deletes;
                throw $this->failure;
            }
        };
        $decorator = new ObjectStorageInstrumentation($composition, $storage);
        $key = new StorageKey('private');
        self::assertSame('application bytes', $decorator->open($key)->read(32));
        self::assertSame(1, $storage->opens);
        try {
            $decorator->delete($key);
            self::fail('Expected storage failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(1, $storage->deletes);
    }

    public function testReadableObjectTimerSurroundsOnlyDelegatedRead(): void
    {
        $events = new \ArrayObject(['initial']);
        $events->exchangeArray([]);
        $span = $this->createStub(\OpenTelemetry\API\Trace\SpanInterface::class);
        $span->method('end')->willReturnCallback(static function () use ($events): void {
            $events[] = 'span end';
        });
        $builder = $this->createStub(\OpenTelemetry\API\Trace\SpanBuilderInterface::class);
        $builder->method('setParent')->willReturnSelf();
        $builder->method('setSpanKind')->willReturnSelf();
        $builder->method('setAttribute')->willReturnSelf();
        $builder->method('startSpan')->willReturnCallback(static function () use ($events, $span): \OpenTelemetry\API\Trace\SpanInterface {
            $events[] = 'trace setup';
            return $span;
        });
        $tracer = $this->createStub(\OpenTelemetry\API\Trace\TracerInterface::class);
        $tracer->method('spanBuilder')->willReturn($builder);
        $traces = $this->createStub(\OpenTelemetry\API\Trace\TracerProviderInterface::class);
        $traces->method('getTracer')->willReturn($tracer);
        $histogram = $this->createStub(\OpenTelemetry\API\Metrics\HistogramInterface::class);
        $histogram->method('record')->willReturnCallback(static function (float|int $amount) use ($events): void {
            \PHPUnit\Framework\Assert::assertSame(0.2, $amount);
            $events[] = 'duration record';
        });
        $counter = $this->createStub(\OpenTelemetry\API\Metrics\CounterInterface::class);
        $counter->method('add')->willReturnCallback(static function () use ($events): void {
            $events[] = 'count record';
        });
        $meter = $this->createStub(\OpenTelemetry\API\Metrics\MeterInterface::class);
        $meter->method('createHistogram')->willReturn($histogram);
        $meter->method('createCounter')->willReturn($counter);
        $meters = $this->createStub(\OpenTelemetry\API\Metrics\MeterProviderInterface::class);
        $meters->method('getMeter')->willReturnCallback(static function () use ($events, $meter): \OpenTelemetry\API\Metrics\MeterInterface {
            $events[] = 'meter setup';
            return $meter;
        });
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'reader-timing-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $traces, meterProvider: $meters, resource: $resource);
        $reader = new class ($events) implements ReadableObject {
            /** @param \ArrayObject<int, string> $events */
            public function __construct(private \ArrayObject $events) {}
            public function read(int $maxBytes): string
            {
                $this->events[] = 'delegate';
                return 'application bytes';
            }
            public function close(): void {}
        };
        $calls = 0;
        $clock = static function () use ($events, &$calls): int {
            ++$calls;
            $events[] = $calls === 1 ? 'clock start' : 'clock end';
            return $calls === 1 ? 1_000_000_000 : 1_200_000_000;
        };
        $decorator = new \Evolve\Observe\Storage\ReadableObjectInstrumentation(
            $composition,
            $reader,
            \OpenTelemetry\Context\Context::getCurrent(),
            $clock,
        );

        self::assertSame('application bytes', $decorator->read(32));
        self::assertSame(
            ['trace setup', 'meter setup', 'clock start', 'delegate', 'clock end', 'duration record', 'count record', 'span end'],
            $events->getArrayCopy(),
        );
    }
}
