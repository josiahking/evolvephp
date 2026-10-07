<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Integration;

use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Observe\Cache\CacheInstrumentation;
use Evolve\Observe\Database\DatabaseConnectionInstrumentation;
use Evolve\Observe\EvolveSemanticConventions as Names;
use Evolve\Observe\Http\HttpClientInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use Evolve\Observe\Storage\ObjectStorageInstrumentation;
use Evolve\Storage\Contracts\ObjectStorage;
use Evolve\Storage\Contracts\ReadableObject;
use Evolve\Storage\Contracts\StorageKey;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

final class InfrastructureTelemetryIntegrationTest extends TestCase
{
    public function testPublicSeamsShareExecutionParentAndReaderRetainsOpeningOrigin(): void
    {
        $exporter = new InMemoryExporter();
        $resource = ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'infrastructure-integration']));
        $provider = new TracerProvider(new SimpleSpanProcessor($exporter), resource: $resource);
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $provider, resource: $resource);
        $connection = new class implements DatabaseConnection {
            public function execute(DatabaseStatement $statement): int
            {
                return 2;
            }
            public function query(DatabaseStatement $statement): iterable
            {
                return [];
            }
            public function transaction(callable $operation): mixed
            {
                return $operation($this);
            }
        };
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturn('cached');
        $reader = new class implements ReadableObject {
            public function read(int $maxBytes): string
            {
                return 'bytes';
            }
            public function close(): void {}
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
        $transport = new class implements ClientInterface {
            public ?RequestInterface $received = null;
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->received = $request;

                return new Response(204);
            }
        };

        $tracer = $provider->getTracer('integration');
        $executionA = $tracer->spanBuilder('execution-a')->startSpan();
        $scopeA = $executionA->activate();
        self::assertSame(2, (new DatabaseConnectionInstrumentation($composition, $connection))->execute(new DatabaseStatement('PRIVATE SQL')));
        self::assertSame('cached', (new CacheInstrumentation($composition, $cache))->get('private-key'));
        $opened = (new ObjectStorageInstrumentation($composition, $storage))->open(new StorageKey('private-key'));
        $response = (new HttpClientInstrumentation($composition))->process(
            new Request('GET', 'https://example.test/private?token=secret', ['baggage' => 'private=value']),
            $transport,
        );
        $scopeA->detach();

        $executionB = $tracer->spanBuilder('execution-b')->startSpan();
        $scopeB = $executionB->activate();
        self::assertSame('bytes', $opened->read(16));
        $opened->close();
        $scopeB->detach();
        $executionB->end();
        $executionA->end();

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('private=value', $transport->received->getHeaderLine('baggage'));
        self::assertStringStartsWith('00-', $transport->received->getHeaderLine('traceparent'));
        $expected = [
            Names::SPAN_NAME_DATABASE,
            Names::SPAN_NAME_CACHE,
            Names::SPAN_NAME_STORAGE,
            Names::SPAN_NAME_HTTP_CLIENT,
        ];
        $infrastructure = array_values(array_filter(
            $exporter->getSpans(),
            static fn($span): bool => in_array($span->getName(), $expected, true),
        ));
        self::assertCount(6, $infrastructure);
        foreach ($infrastructure as $span) {
            self::assertSame($executionA->getContext()->getSpanId(), $span->getParentSpanId());
            self::assertNotSame($executionB->getContext()->getSpanId(), $span->getParentSpanId());
            self::assertStringNotContainsString('private', json_encode($span->getAttributes()->toArray()));
        }
    }
    public function testEachInfrastructureFamilyTimesOnlyDelegatedOperation(): void
    {
        $events = new \ArrayObject(['initial']);
        $ticks = new \SplQueue();
        $clock = static function () use ($events, $ticks): int {
            $events[] = $ticks->count() === 2 ? 'clock start' : 'clock end';

            return $ticks->dequeue();
        };
        $span = $this->createStub(\OpenTelemetry\API\Trace\SpanInterface::class);
        $span->method('end')->willReturnCallback(static function () use ($events): void {
            $events[] = 'span end';
        });
        $span->method('storeInContext')->willReturnCallback(
            static fn(\OpenTelemetry\Context\ContextInterface $context): \OpenTelemetry\Context\ContextInterface => $context,
        );
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
        $histogram->method('record')->willReturnCallback(static function () use ($events): void {
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
        $resource = ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'timing-integration']));
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $traces, meterProvider: $meters, resource: $resource);
        $connection = new class ($events) implements DatabaseConnection {
            /** @param \ArrayObject<int, string> $events */
            public function __construct(private \ArrayObject $events) {}
            public function execute(DatabaseStatement $statement): int
            {
                $this->events[] = 'delegate';
                return 1;
            }
            public function query(DatabaseStatement $statement): iterable
            {
                return [];
            }
            public function transaction(callable $operation): mixed
            {
                return $operation($this);
            }
        };
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(static function () use ($events): string {
            $events[] = 'delegate';
            return 'value';
        });
        $storage = new class ($events) implements ObjectStorage {
            /** @param \ArrayObject<int, string> $events */
            public function __construct(private \ArrayObject $events) {}
            public function put(StorageKey $key, iterable $chunks): void
            {
                $this->events[] = 'delegate';
            }
            public function open(StorageKey $key): ?ReadableObject
            {
                return null;
            }
            public function delete(StorageKey $key): void {}
        };
        $transport = new class ($events) implements ClientInterface {
            /** @param \ArrayObject<int, string> $events */
            public function __construct(private \ArrayObject $events) {}
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->events[] = 'delegate';
                return new Response(204);
            }
        };
        $operations = [
            static fn() => (new DatabaseConnectionInstrumentation($composition, $connection, $clock))->execute(new DatabaseStatement('SQL')),
            static fn() => (new CacheInstrumentation($composition, $cache, $clock))->get('private'),
            static fn() => (new ObjectStorageInstrumentation($composition, $storage, $clock))->put(new StorageKey('private'), []),
            static fn() => (new HttpClientInstrumentation($composition, $clock))->process(new Request('GET', 'https://example.test'), $transport),
        ];
        foreach ($operations as $operation) {
            $events->exchangeArray([]);
            $ticks->enqueue(1_000_000_000);
            $ticks->enqueue(1_200_000_000);
            $operation();
            self::assertSame(
                ['trace setup', 'clock start', 'delegate', 'clock end', 'duration record', 'count record', 'span end'],
                array_values(array_filter($events->getArrayCopy(), static fn($event): bool => $event !== 'meter setup')),
            );
        }
    }

}
