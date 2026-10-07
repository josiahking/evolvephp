<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Integration;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Http\HttpKernel;
use Evolve\Http\Middleware\MiddlewarePipeline;
use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteCollection;
use Evolve\Http\Routing\RouteMatcher;
use Evolve\Http\Routing\RoutingRequestHandler;
use Evolve\Observe\Cache\CacheInstrumentation;
use Evolve\Observe\Database\DatabaseConnectionInstrumentation;
use Evolve\Observe\EvolveSemanticConventions as Names;
use Evolve\Observe\ExecutionMetricsInstrumentation;
use Evolve\Observe\ExecutionTraceInstrumentation;
use Evolve\Observe\Http\HttpClientInstrumentation;
use Evolve\Observe\Http\HttpRouteSpanMiddleware;
use Evolve\Observe\Http\HttpServerMetricsInstrumentation;
use Evolve\Observe\Http\HttpServerTraceInstrumentation;
use Evolve\Observe\Logging\ExecutionLogCorrelationInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use Evolve\Observe\Queue\JobExecutionContextInstrumentation;
use Evolve\Observe\Queue\QueuePublisherInstrumentation;
use Evolve\Observe\Storage\ObjectStorageInstrumentation;
use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueuePublisher;
use Evolve\Storage\Contracts\ObjectStorage;
use Evolve\Storage\Contracts\ReadableObject;
use Evolve\Storage\Contracts\StorageKey;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;

final class ObserveProductAcceptanceTest extends TestCase
{
    public function testCallerOwnedSignalsComposeAcrossHttpExecutionInfrastructureAndQueueWithoutLeakingState(): void
    {
        require_once dirname(__DIR__) . '/Unit/ExecutionMetricsInstrumentationTest.php';

        $exporter = new InMemoryExporter();
        $meters = new \Evolve\Observe\Tests\Unit\RecordingMeterProvider();
        $resource = ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'observe-product-acceptance']));
        $provider = new TracerProvider(new SimpleSpanProcessor($exporter), resource: $resource);
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $provider, meterProvider: $meters, resource: $resource);
        self::assertSame($provider, $composition->tracerProvider());
        self::assertSame($meters, $composition->meterProvider());
        self::assertSame($resource, $composition->resource());

        $connection = new class implements DatabaseConnection {
            public function execute(DatabaseStatement $statement): int
            {
                return 7;
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
            /** @var list<RequestInterface> */
            public array $requests = [];
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                return new Response(204, [], 'client-response-body-secret');
            }
        };
        $receiver = new class implements QueuePublisher {
            /** @var list<MessageEnvelope> */
            public array $messages = [];
            public function publish(QueueName $queue, MessageEnvelope $message): void
            {
                $this->messages[] = $message;
            }
        };
        $database = new DatabaseConnectionInstrumentation($composition, $connection);
        $observedCache = new CacheInstrumentation($composition, $cache);
        $objects = new ObjectStorageInstrumentation($composition, $storage);
        $client = new HttpClientInstrumentation($composition);
        $publisher = new QueuePublisherInstrumentation($composition, $receiver);
        $consumer = new JobExecutionContextInstrumentation($composition);
        $trace = new ExecutionTraceInstrumentation($composition);
        $correlation = new ExecutionLogCorrelationInstrumentation($composition);
        $executionMetrics = new ExecutionMetricsInstrumentation($composition);
        $registry = new ServiceRegistry();
        $registry->freeze();
        $core = new ExecutionOrchestrator($registry, [$executionMetrics, $trace], [$correlation, $trace]);
        $response = new Response(200, [], 'response-body-secret');
        $handler = new class ($database, $observedCache, $objects, $client, $transport, $publisher, $correlation, $response) implements RequestHandlerInterface {
            public function __construct(
                private DatabaseConnectionInstrumentation $database,
                private CacheInstrumentation $cache,
                private ObjectStorageInstrumentation $storage,
                private HttpClientInstrumentation $client,
                private ClientInterface $transport,
                private QueuePublisherInstrumentation $publisher,
                private ExecutionLogCorrelationInstrumentation $correlation,
                private ResponseInterface $response,
            ) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                \PHPUnit\Framework\Assert::assertTrue(Span::getCurrent()->getContext()->isValid());
                \PHPUnit\Framework\Assert::assertNotNull($this->correlation->current()->executionId());
                \PHPUnit\Framework\Assert::assertSame(7, $this->database->execute(new DatabaseStatement('PRIVATE SQL')));
                \PHPUnit\Framework\Assert::assertSame('cached', $this->cache->get('private-cache-key'));
                $reader = $this->storage->open(new StorageKey('private-object-key'));
                \PHPUnit\Framework\Assert::assertNotNull($reader);
                \PHPUnit\Framework\Assert::assertSame('bytes', $reader->read(16));
                $reader->close();
                $outbound = new Request('POST', 'https://outbound-only.example.invalid/client-only-path?client_token=client-query-secret', ['baggage' => 'private=value', 'Authorization' => 'Bearer secret'], 'client-request-body-secret');
                \PHPUnit\Framework\Assert::assertSame(204, $this->client->process($outbound, $this->transport)->getStatusCode());
                $this->publisher->publish(new QueueName('private-queue'), new MessageEnvelope('private-payload', ['baggage' => 'private=value']));

                return $this->response;
            }
        };
        $kernel = new HttpKernel(
            new MiddlewarePipeline([], new RoutingRequestHandler(
                new RouteMatcher(new RouteCollection([new Route(['GET'], '/orders/{id}', $handler)])),
                [new HttpRouteSpanMiddleware()],
            )),
            $core,
        );
        $server = new HttpServerTraceInstrumentation($composition);
        $httpMetrics = new HttpServerMetricsInstrumentation($composition);
        $handle = static fn(ServerRequestInterface $request): ResponseInterface => $httpMetrics->measure(
            $request,
            static fn(ServerRequestInterface $measured): ResponseInterface => $server->trace(
                $measured,
                static fn(ServerRequestInterface $traced): ResponseInterface => $kernel->handle($traced)->primaryResult(),
            ),
        );
        $incoming = new ServerRequest('GET', 'https://example.test/orders/private-id?token=query-secret', [
            'traceparent' => '00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01',
            'baggage' => 'private=value',
            'Authorization' => 'Bearer server-secret',
            'Cookie' => 'session=server-secret',
            'X-Private' => 'arbitrary-header-secret',
        ])->withBody(\GuzzleHttp\Psr7\Utils::streamFor('request-body-secret'));
        self::assertSame($response, $handle($incoming));
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
        self::assertTrue($correlation->current()->isEmpty());
        self::assertCount(1, $receiver->messages);
        self::assertSame('private=value', $receiver->messages[0]->metadata()['baggage']);
        self::assertStringStartsWith('00-', $receiver->messages[0]->metadata()['traceparent']);
        self::assertSame('private=value', $transport->requests[0]->getHeaderLine('baggage'));
        self::assertStringStartsWith('00-', $transport->requests[0]->getHeaderLine('traceparent'));

        $job = $consumer->run($receiver->messages[0], static fn() => $core->execute(
            ExecutionKind::QueueMessage,
            static fn(): string => 'job result',
        ));
        self::assertSame('job result', $job->primaryResult());
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
        self::assertTrue($correlation->current()->isEmpty());

        self::assertSame($response, $handle(new ServerRequest('GET', 'https://example.test/orders/another-id')));
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
        self::assertTrue($correlation->current()->isEmpty());

        $byName = [];
        foreach ($exporter->getSpans() as $span) {
            $byName[$span->getName()][] = $span;
            $attributes = json_encode($span->getAttributes()->toArray());
            foreach (['query-secret', 'client-query-secret', 'client-only-path', 'outbound-only.example.invalid', 'PRIVATE SQL', 'private-queue', 'private-cache-key', 'private-object-key', 'private-payload', 'server-secret', 'arbitrary-header-secret', 'request-body-secret', 'response-body-secret', 'client-request-body-secret', 'client-response-body-secret', 'example.test', 'Bearer', 'private=value'] as $sensitive) {
                self::assertStringNotContainsString($sensitive, $attributes);
            }
            self::assertArrayNotHasKey(UrlAttributes::URL_QUERY, $span->getAttributes()->toArray());
            self::assertArrayNotHasKey(UrlAttributes::URL_FULL, $span->getAttributes()->toArray());
        }
        self::assertCount(2, $byName[Names::SPAN_NAME_HTTP_CLIENT]);
        foreach ($byName[Names::SPAN_NAME_HTTP_CLIENT] as $clientSpan) {
            $clientAttributes = $clientSpan->getAttributes()->toArray();
            self::assertArrayNotHasKey(UrlAttributes::URL_PATH, $clientAttributes);
            self::assertArrayNotHasKey(UrlAttributes::URL_SCHEME, $clientAttributes);
            self::assertArrayNotHasKey(UrlAttributes::URL_QUERY, $clientAttributes);
            self::assertArrayNotHasKey(UrlAttributes::URL_FULL, $clientAttributes);
            self::assertArrayNotHasKey(UrlAttributes::URL_FRAGMENT, $clientAttributes);
            foreach (array_keys($clientAttributes) as $key) {
                self::assertFalse(str_starts_with($key, 'url.'));
            }
        }
        self::assertCount(2, $byName['GET /orders/{id}']);
        self::assertNotSame($byName['GET /orders/{id}'][0]->getTraceId(), $byName['GET /orders/{id}'][1]->getTraceId());
        self::assertSame('/orders/{id}', $byName['GET /orders/{id}'][0]->getAttributes()->get(HttpAttributes::HTTP_ROUTE));
        self::assertSame('/orders/private-id', $byName['GET /orders/{id}'][0]->getAttributes()->get(UrlAttributes::URL_PATH));
        self::assertSame('/orders/another-id', $byName['GET /orders/{id}'][1]->getAttributes()->get(UrlAttributes::URL_PATH));
        self::assertSame('https', $byName['GET /orders/{id}'][0]->getAttributes()->get(UrlAttributes::URL_SCHEME));
        self::assertArrayNotHasKey('GET /orders/private-id', $byName);
        self::assertCount(3, $byName[Names::SPAN_NAME_EXECUTION]);
        self::assertSame($byName['GET /orders/{id}'][0]->getSpanId(), $byName[Names::SPAN_NAME_EXECUTION][0]->getParentSpanId());
        self::assertSame($byName[Names::SPAN_NAME_QUEUE_PRODUCE][0]->getSpanId(), $byName[Names::SPAN_NAME_QUEUE_CONSUME][0]->getParentSpanId());
        self::assertSame($byName[Names::SPAN_NAME_QUEUE_CONSUME][0]->getSpanId(), $byName[Names::SPAN_NAME_EXECUTION][1]->getParentSpanId());
        foreach ($meters->meter->allMeasurements() as $measurement) {
            self::assertNotEmpty($measurement['attributes']);
            self::assertLessThanOrEqual(2, count($measurement['attributes']));
            foreach (array_keys($measurement['attributes']) as $key) {
                self::assertContains($key, [
                    Names::ATTRIBUTE_QUEUE_ROLE,
                    Names::ATTRIBUTE_DATABASE_OPERATION,
                    Names::ATTRIBUTE_CACHE_OPERATION,
                    Names::ATTRIBUTE_STORAGE_OPERATION,
                    HttpAttributes::HTTP_REQUEST_METHOD,
                    Names::ATTRIBUTE_EXECUTION_KIND,
                    Names::ATTRIBUTE_EXECUTION_OUTCOME,
                ]);
            }
            self::assertStringNotContainsString('private', json_encode($measurement['attributes']));
            self::assertStringNotContainsString('query-secret', json_encode($measurement['attributes']));
            self::assertStringNotContainsString('/orders/', json_encode($measurement['attributes']));
            self::assertStringNotContainsString('example.test', json_encode($measurement['attributes']));
            self::assertStringNotContainsString('PRIVATE SQL', json_encode($measurement['attributes']));
            self::assertStringNotContainsString('private-queue', json_encode($measurement['attributes']));
            self::assertStringNotContainsString('outbound-only.example.invalid', json_encode($measurement['attributes']));
            self::assertStringNotContainsString('client-only-path', json_encode($measurement['attributes']));
            self::assertStringNotContainsString('client-query-secret', json_encode($measurement['attributes']));
        }

        $disabled = OpenTelemetryComposition::disabled();
        $disabledServer = new HttpServerTraceInstrumentation($disabled);
        $disabledDatabase = new DatabaseConnectionInstrumentation($disabled, $connection);
        $disabledResponse = new Response(202);
        $disabledRequest = new ServerRequest('GET', 'https://example.test/orders/private-id');
        $spanCount = count($exporter->getSpans());
        self::assertSame($disabledResponse, $disabledServer->trace(
            $disabledRequest,
            static function (ServerRequestInterface $received) use ($disabledRequest, $disabledDatabase, $disabledResponse): ResponseInterface {
                self::assertSame($disabledRequest, $received);
                self::assertSame(7, $disabledDatabase->execute(new DatabaseStatement('PRIVATE SQL')));

                return $disabledResponse;
            },
        ));
        self::assertCount($spanCount, $exporter->getSpans());
        self::assertFalse(Span::getCurrent()->getContext()->isValid());

        $applicationFailure = new \RuntimeException('private application failure');
        $caught = null;
        try {
            $server->trace(
                new ServerRequest('GET', 'https://example.test/orders/failure'),
                static function () use ($applicationFailure): ResponseInterface {
                    throw $applicationFailure;
                },
            );
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }
        self::assertSame($applicationFailure, $caught);
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
        self::assertStringNotContainsString(
            'private application failure',
            json_encode($exporter->getSpans()[count($exporter->getSpans()) - 1]->getAttributes()->toArray()),
        );
    }
}
