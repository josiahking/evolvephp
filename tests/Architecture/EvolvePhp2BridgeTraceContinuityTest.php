<?php

declare(strict_types=1);

use Evolve\Bridge\Contracts\BridgeContext;
use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Psr\EmbeddedBridgeAdapter;
use Evolve\Bridge\Remote\RemoteBridgeAuthenticator;
use Evolve\Bridge\Remote\RemoteBridgeClient;
use Evolve\Bridge\Remote\RemoteBridgeClientAuthenticator;
use Evolve\Bridge\Remote\RemoteBridgeCodec;
use Evolve\Bridge\Remote\RemoteBridgeInvocation;
use Evolve\Bridge\Remote\RemoteBridgeProtocol;
use Evolve\Bridge\Remote\RemoteBridgeServerHandler;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Http\Health\ReadinessCheck;
use Evolve\Http\HttpKernel;
use Evolve\Http\Response\ExecutionOutcomeResponseResolver;
use Evolve\Observe\EvolveSemanticConventions;
use Evolve\Observe\ExecutionTraceInstrumentation;
use Evolve\Observe\Http\HttpServerTraceInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class EvolvePhp2BridgeTraceContinuityTest extends TestCase
{
    public function testEmbeddedBridgeKeepsActiveSpanAsExecutionParentWithoutServerSpanLeak(): void
    {
        [$composition, $exporter, $provider] = $this->compositionHarness();
        $adapter = $this->embeddedAdapter($composition, new BridgeTraceContinuityHandler());
        $parent = $provider->getTracer('bridge-continuity-test')->spanBuilder('caller-parent')->startSpan();
        $scope = $parent->activate();

        try {
            $first = $adapter->invoke(
                new ContinuityServerRequest('GET', '/embedded'),
                new BridgeContext('request-1', 'correlation-1'),
            );
        } finally {
            $scope->detach();
            $parent->end();
        }

        $second = $adapter->invoke(
            new ContinuityServerRequest('GET', '/embedded-second'),
            new BridgeContext('request-2', 'correlation-2'),
        );

        $executionSpans = $this->spansNamed($exporter, EvolveSemanticConventions::SPAN_NAME_EXECUTION);

        self::assertSame(204, $first->response()?->getStatusCode());
        self::assertSame(204, $second->response()?->getStatusCode());
        self::assertCount(2, $executionSpans);
        self::assertSame($parent->getContext()->getTraceId(), $executionSpans[0]->getTraceId());
        self::assertSame($parent->getContext()->getSpanId(), $executionSpans[0]->getParentSpanId());
        self::assertSame(str_repeat('0', 16), $executionSpans[1]->getParentSpanId());
        self::assertNotSame($executionSpans[0]->getTraceId(), $executionSpans[1]->getTraceId());
        self::assertCount(0, $this->spansNamed($exporter, 'GET'));
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testRemoteBridgeProjectsCallerTraceToServerAndExecutionSpan(): void
    {
        [$composition, $exporter, $provider] = $this->compositionHarness();
        $codec = new RemoteBridgeCodec();
        $server = new RemoteBridgeServerHandler(
            $this->embeddedAdapter($composition, new BridgeTraceContinuityHandler()),
            $codec,
            new ContinuityRemoteAuthenticator(),
            new ContinuityResponseFactory(),
            new ContinuityServerRequestFactory(),
            new ContinuityStreamFactory(),
        );
        $http = new ContinuityLoopbackHttpClient(
            new HttpServerTraceInstrumentation($composition),
            $server,
        );
        $caller = $provider->getTracer('bridge-continuity-test')->spanBuilder('remote-caller')->startSpan();
        $callerContext = $caller->getContext();
        $invocation = $this->remoteInvocation(sprintf(
            '00-%s-%s-01',
            $callerContext->getTraceId(),
            $callerContext->getSpanId(),
        ));
        $client = new RemoteBridgeClient(
            'https://remote.example.test/bridge',
            $http,
            new ContinuityRequestFactory(),
            new ContinuityStreamFactory(),
            $codec,
            new ContinuityClientAuthenticator(),
        );

        $result = $client->invoke($invocation);
        $caller->end();

        $serverSpan = $this->singleSpanNamed($exporter, 'POST');
        $executionSpan = $this->singleSpanNamed($exporter, EvolveSemanticConventions::SPAN_NAME_EXECUTION);

        self::assertNull($result->error());
        self::assertSame(204, $result->result()?->applicationStatus());
        self::assertSame($callerContext->getTraceId(), $serverSpan->getTraceId());
        self::assertSame($callerContext->getSpanId(), $serverSpan->getParentSpanId());
        self::assertSame($serverSpan->getTraceId(), $executionSpan->getTraceId());
        self::assertSame($serverSpan->getSpanId(), $executionSpan->getParentSpanId());
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testMalformedRemoteTraceContextIsNonFatal(): void
    {
        [$composition, $exporter] = $this->compositionHarness();
        $codec = new RemoteBridgeCodec();
        $client = new RemoteBridgeClient(
            'https://remote.example.test/bridge',
            new ContinuityLoopbackHttpClient(
                new HttpServerTraceInstrumentation($composition),
                new RemoteBridgeServerHandler(
                    $this->embeddedAdapter($composition, new BridgeTraceContinuityHandler()),
                    $codec,
                    new ContinuityRemoteAuthenticator(),
                    new ContinuityResponseFactory(),
                    new ContinuityServerRequestFactory(),
                    new ContinuityStreamFactory(),
                ),
            ),
            new ContinuityRequestFactory(),
            new ContinuityStreamFactory(),
            $codec,
            new ContinuityClientAuthenticator(),
        );

        $result = $client->invoke($this->remoteInvocation('not-valid-but-transport-safe'));

        self::assertNull($result->error());
        self::assertSame(204, $result->result()?->applicationStatus());
        self::assertSame(str_repeat('0', 16), $this->singleSpanNamed($exporter, 'POST')->getParentSpanId());
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    /**
     * @return array{0: OpenTelemetryComposition, 1: InMemoryExporter, 2: TracerProvider}
     */
    private function compositionHarness(): array
    {
        $exporter = new InMemoryExporter();
        $resource = ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'bridge-continuity-test']));
        $provider = new TracerProvider(new SimpleSpanProcessor($exporter), resource: $resource);

        return [
            new OpenTelemetryComposition(
                enabled: true,
                tracerProvider: $provider,
                resource: $resource,
            ),
            $exporter,
            $provider,
        ];
    }

    private function embeddedAdapter(OpenTelemetryComposition $composition, RequestHandlerInterface $handler): EmbeddedBridgeAdapter
    {
        $trace = new ExecutionTraceInstrumentation($composition);
        $services = new ServiceRegistry();
        $services->freeze();

        return new EmbeddedBridgeAdapter(
            new HttpKernel($handler, new ExecutionOrchestrator($services, $trace, [$trace])),
            new ExecutionOutcomeResponseResolver(new ContinuityResponseFactory()),
            new ContinuityReady(),
        );
    }

    private function remoteInvocation(string $traceparent): RemoteBridgeInvocation
    {
        return new RemoteBridgeInvocation(
            operation: 'continuity.test',
            method: 'GET',
            target: '/delegated',
            headers: ['accept' => ['application/json']],
            body: '',
            payload: ['ok' => true],
            requestIdentifier: 'request-1',
            correlationIdentifier: 'correlation-1',
            callerIdentifier: 'caller-1',
            deadline: '2999-01-01T00:00:00+00:00',
            trace: ['traceparent' => $traceparent, 'tracestate' => 'vendor=value'],
        );
    }

    /**
     * @return list<SpanDataInterface>
     */
    private function spansNamed(InMemoryExporter $exporter, string $name): array
    {
        return array_values(array_filter(
            $exporter->getSpans(),
            static fn(SpanDataInterface $span): bool => $span->getName() === $name,
        ));
    }

    private function singleSpanNamed(InMemoryExporter $exporter, string $name): SpanDataInterface
    {
        $spans = $this->spansNamed($exporter, $name);

        self::assertCount(1, $spans, 'Expected one span named ' . $name . '.');

        return $spans[0];
    }
}

final class ContinuityReady implements ReadinessCheck
{
    public function isReady(): bool
    {
        return true;
    }
}

final class BridgeTraceContinuityHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        TestCase::assertTrue(Span::getCurrent()->getContext()->isValid());

        return new ContinuityResponse(204);
    }
}

final class ContinuityClientAuthenticator implements RemoteBridgeClientAuthenticator
{
    public function authenticationHeaders(RequestInterface $request, RemoteBridgeInvocation $invocation): array
    {
        return [];
    }
}

final class ContinuityRemoteAuthenticator implements RemoteBridgeAuthenticator
{
    public function authenticate(ServerRequestInterface $request, RemoteBridgeInvocation $invocation): ?BridgeError
    {
        return null;
    }
}

final class ContinuityLoopbackHttpClient implements ClientInterface
{
    public function __construct(
        private HttpServerTraceInstrumentation $instrumentation,
        private RemoteBridgeServerHandler $server,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $serverRequest = new ContinuityServerRequest(
            $request->getMethod(),
            (string) $request->getUri(),
            $request->getHeaders(),
            (string) $request->getBody(),
        );

        return $this->instrumentation->trace(
            $serverRequest,
            fn(ServerRequestInterface $tracedRequest): ResponseInterface => $this->server->handle($tracedRequest),
        );
    }
}

final class ContinuityRequestFactory implements RequestFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new ContinuityRequest($method, $uri instanceof UriInterface ? $uri : new ContinuityUri((string) $uri));
    }
}

final class ContinuityServerRequestFactory implements ServerRequestFactoryInterface
{
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new ContinuityServerRequest($method, $uri instanceof UriInterface ? $uri : new ContinuityUri((string) $uri));
    }
}

final class ContinuityResponseFactory implements ResponseFactoryInterface
{
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new ContinuityResponse($code);
    }
}

final class ContinuityStreamFactory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        return new ContinuityStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        throw new RuntimeException('Not used.');
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        throw new RuntimeException('Not used.');
    }
}

class ContinuityMessage implements MessageInterface
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        protected array $headers = [],
        protected StreamInterface $body = new ContinuityStream(),
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        return clone $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return $this->getHeader($name) !== [];
    }

    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(',', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = array_values((array) $value);

        return $clone;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $key = strtolower($name);
        $clone->headers[$key] = array_merge($clone->headers[$key] ?? [], array_values((array) $value));

        return $clone;
    }

    public function withoutHeader(string $name): MessageInterface
    {
        $clone = clone $this;
        unset($clone->headers[strtolower($name)]);

        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }
}

class ContinuityRequest extends ContinuityMessage implements RequestInterface
{
    private UriInterface $uri;

    public function __construct(
        private string $method,
        string|UriInterface $uri,
        array $headers = [],
        string $body = '',
    ) {
        parent::__construct($headers, new ContinuityStream($body));
        $this->uri = $uri instanceof UriInterface ? $uri : new ContinuityUri($uri);
    }

    public function getRequestTarget(): string
    {
        return (string) $this->uri;
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): RequestInterface
    {
        $clone = clone $this;
        $clone->method = $method;

        return $clone;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        $clone = clone $this;
        $clone->uri = $uri;

        return $clone;
    }
}

final class ContinuityServerRequest extends ContinuityRequest implements ServerRequestInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $attributes = [];

    public function getServerParams(): array
    {
        return [];
    }

    public function getCookieParams(): array
    {
        return [];
    }

    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        return $this;
    }

    public function getQueryParams(): array
    {
        return [];
    }

    public function withQueryParams(array $query): ServerRequestInterface
    {
        return $this;
    }

    public function getUploadedFiles(): array
    {
        return [];
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        return $this;
    }

    public function getParsedBody(): object|array|null
    {
        return null;
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        return $this;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, mixed $value): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }

    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);

        return $clone;
    }
}

final class ContinuityResponse extends ContinuityMessage implements ResponseInterface
{
    public function __construct(private int $status = 200, array $headers = [], string $body = '')
    {
        parent::__construct($headers, new ContinuityStream($body));
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $clone = clone $this;
        $clone->status = $code;

        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return '';
    }
}

final readonly class ContinuityUri implements UriInterface
{
    public function __construct(private string $value) {}

    public function getScheme(): string
    {
        return (string) parse_url($this->value, PHP_URL_SCHEME);
    }

    public function getAuthority(): string
    {
        return (string) parse_url($this->value, PHP_URL_HOST);
    }

    public function getUserInfo(): string
    {
        return '';
    }

    public function getHost(): string
    {
        return (string) parse_url($this->value, PHP_URL_HOST);
    }

    public function getPort(): ?int
    {
        return parse_url($this->value, PHP_URL_PORT);
    }

    public function getPath(): string
    {
        return (string) parse_url($this->value, PHP_URL_PATH);
    }

    public function getQuery(): string
    {
        return (string) parse_url($this->value, PHP_URL_QUERY);
    }

    public function getFragment(): string
    {
        return '';
    }

    public function withScheme(string $scheme): UriInterface
    {
        return new self($this->value);
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        return new self($this->value);
    }

    public function withHost(string $host): UriInterface
    {
        return new self($this->value);
    }

    public function withPort(?int $port): UriInterface
    {
        return new self($this->value);
    }

    public function withPath(string $path): UriInterface
    {
        return new self($path);
    }

    public function withQuery(string $query): UriInterface
    {
        return new self($this->value);
    }

    public function withFragment(string $fragment): UriInterface
    {
        return new self($this->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

final class ContinuityStream implements StreamInterface
{
    public function __construct(private string $content = '') {}

    public function __toString(): string
    {
        return $this->content;
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): int
    {
        return strlen($this->content);
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void {}

    public function rewind(): void {}

    public function isWritable(): bool
    {
        return true;
    }

    public function write(string $string): int
    {
        $this->content .= $string;

        return strlen($string);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        return $this->content;
    }

    public function getContents(): string
    {
        return $this->content;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return null;
    }
}
