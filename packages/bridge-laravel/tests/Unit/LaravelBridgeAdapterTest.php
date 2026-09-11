<?php

declare(strict_types=1);

namespace Evolve\Bridge\Laravel\Tests\Unit;

use BadMethodCallException;
use Evolve\Bridge\Contracts\BridgeContext;
use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Contracts\BridgeErrorKind;
use Evolve\Bridge\Laravel\LaravelBridgeAdapter;
use Evolve\Bridge\Laravel\LaravelBridgeResult;
use Evolve\Bridge\Psr\EmbeddedBridgeAdapter;
use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Http\Health\ReadinessCheck;
use Evolve\Http\HttpKernel;
use Evolve\Http\Response\ExecutionOutcomeResponseResolver;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

final class LaravelBridgeAdapterTest extends TestCase
{
    public function test_representative_laravel_get_route_delegates_once_and_returns_sanitized_laravel_response(): void
    {
        $handler = new LaravelBridgeRecordingHandler(new LaravelBridgePsrResponse(
            202,
            'delegated',
            [
                'Content-Type' => ['application/json'],
                'Cache-Control' => ['no-store'],
                'X-Internal' => ['hidden'],
                'Set-Cookie' => ['secret=1'],
            ],
        ));
        $adapter = $this->adapter($handler);
        $context = new BridgeContext('request-1', 'correlation-1', 'principal-1', 'tenant-1');
        $request = Request::create(
            '/evolve/delegated?search=term',
            'GET',
            [],
            ['laravel_session' => 'secret'],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer secret',
                'HTTP_TRACEPARENT' => '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01',
                'HTTP_X_FORWARDED_USER' => 'host-user',
            ],
        );
        $request->attributes->set('route', (object) ['controller' => 'HostController']);
        $request->setLaravelSession(new LaravelBridgeSessionProbe());

        $result = $adapter->invoke($request, $context);
        $response = $result->response();

        self::assertInstanceOf(Response::class, $response);
        self::assertNull($result->error());
        self::assertTrue($result->isReusable());
        self::assertFalse($result->requiresQuarantine());
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('delegated', $response->getContent());
        self::assertSame('application/json', $response->headers->get('content-type'));
        self::assertStringContainsString('no-store', $response->headers->get('cache-control') ?? '');
        self::assertFalse($response->headers->has('x-internal'));
        self::assertFalse($response->headers->has('set-cookie'));

        self::assertSame(1, $handler->calls);
        $psrRequest = $handler->requests[0];
        self::assertSame('GET', $psrRequest->getMethod());
        self::assertSame('http://localhost/evolve/delegated?search=term', (string) $psrRequest->getUri());
        self::assertSame(['search' => 'term'], $psrRequest->getQueryParams());
        self::assertSame([], $psrRequest->getCookieParams());
        self::assertSame([], $psrRequest->getUploadedFiles());
        self::assertSame([], $psrRequest->getServerParams());
        self::assertArrayNotHasKey('route', $psrRequest->getAttributes());
        self::assertArrayNotHasKey('session', $psrRequest->getAttributes());
        self::assertSame($context, $psrRequest->getAttribute(BridgeContext::class));
        self::assertSame(['application/json'], $psrRequest->getHeader('accept'));
        self::assertSame(['00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01'], $psrRequest->getHeader('traceparent'));
        self::assertFalse($psrRequest->hasHeader('authorization'));
        self::assertFalse($psrRequest->hasHeader('x-forwarded-user'));
        self::assertSame('HostController', $request->attributes->get('route')->controller);
    }

    public function test_post_body_and_parsed_body_are_translated_without_forwarding_host_objects(): void
    {
        $handler = new LaravelBridgeRecordingHandler(new LaravelBridgePsrResponse(204, ''));
        $adapter = $this->adapter($handler);
        $request = Request::create(
            '/evolve/delegated',
            'POST',
            ['name' => 'Ada'],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"name":"Ada"}',
        );

        $result = $adapter->invoke($request, new BridgeContext('request-1', 'correlation-1'));

        self::assertSame(204, $result->response()?->getStatusCode());
        self::assertSame('{"name":"Ada"}', (string) $handler->requests[0]->getBody());
        self::assertSame(['name' => 'Ada'], $handler->requests[0]->getParsedBody());
        self::assertSame(['application/json'], $handler->requests[0]->getHeader('content-type'));
    }

    public function test_nested_query_and_parsed_body_list_arrays_are_preserved(): void
    {
        $handler = new LaravelBridgeRecordingHandler(new LaravelBridgePsrResponse(204, ''));
        $adapter = $this->adapter($handler);
        $request = Request::create(
            '/evolve/delegated',
            'POST',
            [
                'items' => [
                    ['id' => 1],
                    ['id' => 2],
                ],
            ],
        );
        $request->query->set('filters', [
            'active' => true,
            'tags' => ['alpha', 'beta'],
        ]);

        $result = $adapter->invoke($request, new BridgeContext('request-1', 'correlation-1'));

        self::assertSame(204, $result->response()?->getStatusCode());
        self::assertSame(1, $handler->calls);
        self::assertSame(
            [
                'filters' => [
                    'active' => true,
                    'tags' => ['alpha', 'beta'],
                ],
            ],
            $handler->requests[0]->getQueryParams(),
        );
        self::assertSame(
            [
                'items' => [
                    ['id' => 1],
                    ['id' => 2],
                ],
            ],
            $handler->requests[0]->getParsedBody(),
        );
    }

    public function test_not_ready_bridge_failure_is_preserved_without_successful_response_fabrication(): void
    {
        $handler = new LaravelBridgeRecordingHandler(new LaravelBridgePsrResponse(200, 'unused'));
        $result = $this->adapter($handler, ready: false)->invoke(
            Request::create('/delegated', 'GET'),
            new BridgeContext('request-1', 'correlation-1'),
        );

        self::assertNull($result->response());
        self::assertInstanceOf(BridgeError::class, $result->error());
        self::assertSame(BridgeErrorKind::BootOrReadiness, $result->error()->kind());
        self::assertSame('embedded_not_ready', $result->error()->code());
        self::assertTrue($result->isReusable());
        self::assertFalse($result->requiresQuarantine());
        self::assertSame(0, $handler->calls);
    }

    public function test_reset_quarantine_preserves_response_and_quarantine_error(): void
    {
        $handler = new LaravelBridgeRecordingHandler(
            new LaravelBridgePsrResponse(209, 'primary'),
            resetFailure: new RuntimeException('host-sensitive reset failure'),
        );

        $result = $this->adapter($handler)->invoke(
            Request::create('/delegated', 'GET'),
            new BridgeContext('request-1', 'correlation-1'),
        );
        $response = $result->response();
        $error = $result->error();

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(BridgeError::class, $error);
        self::assertSame(209, $response->getStatusCode());
        self::assertSame('primary', $response->getContent());
        self::assertSame(BridgeErrorKind::ResetOrQuarantine, $error->kind());
        self::assertTrue($result->requiresQuarantine());
        self::assertFalse($result->isReusable());
    }

    public function test_translation_failure_before_evolve_execution_is_safe_and_non_retryable(): void
    {
        $handler = new LaravelBridgeRecordingHandler(new LaravelBridgePsrResponse(200, 'unused'));
        $adapter = $this->adapter($handler, streamFactory: new LaravelBridgeThrowingStreamFactory());

        $result = $adapter->invoke(
            Request::create('/delegated', 'POST', content: 'secret body'),
            new BridgeContext('request-1', 'correlation-1'),
        );
        $error = $result->error();

        self::assertNull($result->response());
        self::assertSame(0, $handler->calls);
        self::assertInstanceOf(BridgeError::class, $error);
        self::assertSame(BridgeErrorKind::Translation, $error->kind());
        self::assertSame('laravel_request_translation_failed', $error->code());
        self::assertSame('Laravel request could not be safely translated for delegated Evolve execution.', $error->message());
        self::assertFalse($error->isRetryable());
        self::assertStringNotContainsString('RuntimeException', $error->message());
        self::assertTrue($result->isReusable());
    }

    public function test_quarantine_remains_visible_when_laravel_response_translation_fails_after_execution(): void
    {
        $handler = new LaravelBridgeRecordingHandler(
            new LaravelBridgeThrowingBodyResponse(),
            resetFailure: new RuntimeException('reset failure details'),
        );

        $result = $this->adapter($handler)->invoke(
            Request::create('/delegated', 'GET'),
            new BridgeContext('request-1', 'correlation-1'),
        );
        $error = $result->error();

        self::assertNull($result->response());
        self::assertSame(1, $handler->calls);
        self::assertInstanceOf(BridgeError::class, $error);
        self::assertSame(BridgeErrorKind::ResetOrQuarantine, $error->kind());
        self::assertSame('embedded_process_quarantined', $error->code());
        self::assertTrue($result->requiresQuarantine());
        self::assertFalse($result->isReusable());
    }

    public function test_laravel_result_rejects_impossible_state_combinations(): void
    {
        $response = new Response('ok');
        $translationError = new BridgeError(BridgeErrorKind::Translation, 'translation_failed', 'Translation failed.', false);
        $quarantineError = new BridgeError(BridgeErrorKind::ResetOrQuarantine, 'embedded_process_quarantined', 'Quarantined.', false);

        $this->assertInvalidResultState(null, null, false, 'Laravel bridge results require either a response or an error.');
        $this->assertInvalidResultState($response, $translationError, false, 'Reusable Laravel bridge responses must not include an error.');
        $this->assertInvalidResultState($response, null, true, 'Laravel bridge quarantine requires an error.');
        $this->assertInvalidResultState($response, $translationError, true, 'Laravel bridge quarantine requires a reset or quarantine error.');
        $this->assertInvalidResultState(null, $quarantineError, false, 'Laravel bridge reset or quarantine errors require quarantine.');

        $valid = new LaravelBridgeResult(null, $quarantineError, true);
        self::assertTrue($valid->requiresQuarantine());
        self::assertFalse($valid->isReusable());
    }

    private function adapter(
        RequestHandlerInterface $handler,
        bool $ready = true,
        ?StreamFactoryInterface $streamFactory = null,
    ): LaravelBridgeAdapter {
        $registry = new ServiceRegistry();
        $registry->freeze();

        return new LaravelBridgeAdapter(
            new EmbeddedBridgeAdapter(
                new HttpKernel($handler, new ExecutionOrchestrator($registry)),
                new ExecutionOutcomeResponseResolver(new LaravelBridgeResponseFactory()),
                new LaravelBridgeReadinessCheck($ready),
            ),
            new LaravelBridgeServerRequestFactory(),
            $streamFactory ?? new LaravelBridgeStreamFactory(),
        );
    }

    private function assertInvalidResultState(
        ?Response $response,
        ?BridgeError $error,
        bool $requiresQuarantine,
        string $message,
    ): void {
        try {
            new LaravelBridgeResult($response, $error, $requiresQuarantine);
            self::fail('Expected LogicException for invalid Laravel bridge result state.');
        } catch (LogicException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}

final readonly class LaravelBridgeReadinessCheck implements ReadinessCheck
{
    public function __construct(private bool $ready) {}

    public function isReady(): bool
    {
        return $this->ready;
    }
}

final class LaravelBridgeRecordingHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    /**
     * @var list<ServerRequestInterface>
     */
    public array $requests = [];

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly ?Throwable $resetFailure = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        ++$this->calls;
        $this->requests[] = $request;

        if ($this->resetFailure !== null) {
            $scope = $request->getAttribute(ExecutionScope::class);
            if (! $scope instanceof ExecutionScope) {
                throw new RuntimeException('Execution scope missing.');
            }

            $scope->registerResetParticipant('laravel-bridge-test-reset', new LaravelBridgeFailingResetParticipant($this->resetFailure));
        }

        return $this->response;
    }
}

final readonly class LaravelBridgeFailingResetParticipant implements ResetParticipant
{
    public function __construct(private Throwable $throwable) {}

    public function reset(): void
    {
        throw $this->throwable;
    }
}

final readonly class LaravelBridgeResponseFactory implements \Psr\Http\Message\ResponseFactoryInterface
{
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new LaravelBridgePsrResponse($code, '', [], $reasonPhrase);
    }
}

final readonly class LaravelBridgeServerRequestFactory implements ServerRequestFactoryInterface
{
    /**
     * @param array<string, mixed> $serverParams
     */
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new LaravelBridgePsrServerRequest($method, new LaravelBridgeUri((string) $uri), $serverParams);
    }
}

final readonly class LaravelBridgeStreamFactory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        return new LaravelBridgeStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        throw new BadMethodCallException('Not used by these tests.');
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return new LaravelBridgeStream((string) stream_get_contents($resource));
    }
}

final readonly class LaravelBridgeThrowingStreamFactory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        throw new RuntimeException('raw host body leaked');
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        throw new RuntimeException('not used');
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        throw new RuntimeException('not used');
    }
}

final class LaravelBridgePsrServerRequest implements ServerRequestInterface
{
    /**
     * @param array<string, mixed> $serverParams
     * @param array<string, list<string>> $headers
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed>|object|null $parsedBody
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private string $method,
        private UriInterface $uri,
        private array $serverParams = [],
        private array $headers = [],
        private ?StreamInterface $body = null,
        private array $queryParams = [],
        private mixed $parsedBody = null,
        private array $attributes = [],
    ) {}

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->headers);
    }

    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = is_array($value) ? array_values($value) : [(string) $value];

        return $clone;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = array_merge($clone->headers[strtolower($name)] ?? [], is_array($value) ? array_values($value) : [(string) $value]);

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
        return $this->body ?? new LaravelBridgeStream('');
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
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

    /**
     * @return array<string, mixed>
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * @return array<string, string>
     */
    public function getCookieParams(): array
    {
        return [];
    }

    /**
     * @param array<string, string> $cookies
     */
    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function withQueryParams(array $query): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->queryParams = $query;

        return $clone;
    }

    /**
     * @return array<string, UploadedFileInterface>
     */
    public function getUploadedFiles(): array
    {
        return [];
    }

    /**
     * @param array<string, UploadedFileInterface> $uploadedFiles
     */
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        return $this;
    }

    /**
     * @return array<mixed, mixed>|object|null
     */
    public function getParsedBody(): mixed
    {
        return $this->parsedBody;
    }

    /**
     * @param array<mixed, mixed>|object|null $data
     */
    public function withParsedBody($data): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->parsedBody = $data;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, $value): ServerRequestInterface
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

final class LaravelBridgePsrResponse implements ResponseInterface
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        private int $statusCode,
        private string $body,
        private array $headers = [],
        private string $reasonPhrase = '',
    ) {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[strtolower($name)] = $values;
        }

        $this->headers = $normalized;
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->headers);
    }

    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        return $this;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        return $this;
    }

    public function withoutHeader(string $name): MessageInterface
    {
        return $this;
    }

    public function getBody(): StreamInterface
    {
        return new LaravelBridgeStream($this->body);
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        return new self($code, $this->body, $this->headers, $reasonPhrase);
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }
}

final class LaravelBridgeThrowingBodyResponse implements ResponseInterface
{
    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function hasHeader(string $name): bool
    {
        return false;
    }

    public function getHeader(string $name): array
    {
        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return '';
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        return $this;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        return $this;
    }

    public function withoutHeader(string $name): MessageInterface
    {
        return $this;
    }

    public function getBody(): StreamInterface
    {
        throw new RuntimeException('sensitive response conversion details');
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        return $this;
    }

    public function getStatusCode(): int
    {
        return 200;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        return $this;
    }

    public function getReasonPhrase(): string
    {
        return '';
    }
}

final readonly class LaravelBridgeUri implements UriInterface
{
    public function __construct(private string $uri) {}

    public function getScheme(): string
    {
        return (string) parse_url($this->uri, PHP_URL_SCHEME);
    }

    public function getAuthority(): string
    {
        return $this->getHost();
    }

    public function getUserInfo(): string
    {
        return '';
    }

    public function getHost(): string
    {
        return (string) parse_url($this->uri, PHP_URL_HOST);
    }

    public function getPort(): ?int
    {
        return null;
    }

    public function getPath(): string
    {
        return (string) parse_url($this->uri, PHP_URL_PATH);
    }

    public function getQuery(): string
    {
        return (string) parse_url($this->uri, PHP_URL_QUERY);
    }

    public function getFragment(): string
    {
        return '';
    }

    public function withScheme(string $scheme): UriInterface
    {
        return $this;
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        return $this;
    }

    public function withHost(string $host): UriInterface
    {
        return $this;
    }

    public function withPort(?int $port): UriInterface
    {
        return $this;
    }

    public function withPath(string $path): UriInterface
    {
        return $this;
    }

    public function withQuery(string $query): UriInterface
    {
        return $this;
    }

    public function withFragment(string $fragment): UriInterface
    {
        return $this;
    }

    public function __toString(): string
    {
        return $this->uri;
    }
}

final readonly class LaravelBridgeStream implements StreamInterface
{
    public function __construct(private string $content) {}

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
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void {}

    public function rewind(): void {}

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new BadMethodCallException('Read-only stream.');
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

final class LaravelBridgeSessionProbe implements Session
{
    public function getName(): string
    {
        return 'laravel_session';
    }

    public function setName($name): void {}

    public function getId(): string
    {
        return 'session-id';
    }

    public function setId($id): void {}

    public function start(): bool
    {
        return true;
    }

    public function save(): void {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return ['secret' => 'value'];
    }

    /**
     * @param string|array<int, string> $key
     */
    public function exists($key): bool
    {
        return false;
    }

    /**
     * @param string|array<int, string> $key
     */
    public function has($key): bool
    {
        return false;
    }

    public function get($key, $default = null): mixed
    {
        return $default;
    }

    public function pull($key, $default = null): mixed
    {
        return $default;
    }

    /**
     * @param string|array<string, mixed> $key
     */
    public function put($key, $value = null): void {}

    public function flash(string $key, $value = true): void {}

    public function token(): string
    {
        return 'csrf-token';
    }

    public function regenerateToken(): void {}

    public function remove($key): mixed
    {
        return null;
    }

    /**
     * @param string|array<int, string> $keys
     */
    public function forget($keys): void {}

    public function flush(): void {}

    public function invalidate(): bool
    {
        return true;
    }

    public function regenerate($destroy = false): bool
    {
        return true;
    }

    public function migrate($destroy = false): bool
    {
        return true;
    }

    public function isStarted(): bool
    {
        return true;
    }

    public function previousUrl(): ?string
    {
        return null;
    }

    public function setPreviousUrl($url): void {}

    public function getHandler(): \SessionHandlerInterface
    {
        return new \SessionHandler();
    }

    public function handlerNeedsRequest(): bool
    {
        return false;
    }

    public function setRequestOnHandler($request): void {}
}
