<?php

declare(strict_types=1);

namespace Evolve\Bridge\Remote\Tests\Unit;

use Evolve\Bridge\Contracts\BridgeContext;
use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Contracts\BridgeErrorKind;
use Evolve\Bridge\Psr\EmbeddedBridgeAdapter;
use Evolve\Bridge\Remote\RemoteBridgeAuthenticator;
use Evolve\Bridge\Remote\RemoteBridgeCodec;
use Evolve\Bridge\Remote\RemoteBridgeInvocation;
use Evolve\Bridge\Remote\RemoteBridgeProtocol;
use Evolve\Bridge\Remote\RemoteBridgeServerHandler;
use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Http\Health\ReadinessCheck;
use Evolve\Http\HttpKernel;
use Evolve\Http\Response\ExecutionOutcomeResponseResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class RemoteBridgeServerHandlerTest extends TestCase
{
    public function test_wrong_http_method_media_type_version_auth_and_deadline_fail_before_delegation(): void
    {
        foreach ([
            'method' => [$this->outerRequest('GET'), null, 405, BridgeErrorKind::Protocol],
            'media' => [$this->outerRequest(contentType: 'text/plain'), null, 415, BridgeErrorKind::Protocol],
            'version' => [$this->outerRequest(body: str_replace('"version":1', '"version":2', $this->validBody())), null, 400, BridgeErrorKind::Protocol],
            'authentication' => [$this->outerRequest(), new BridgeError(BridgeErrorKind::Authentication, 'auth_failed', 'Authentication failed.', false), 401, BridgeErrorKind::Authentication],
            'authorization' => [$this->outerRequest(), new BridgeError(BridgeErrorKind::Authorization, 'not_allowed', 'Operation is not allowed.', false), 403, BridgeErrorKind::Authorization],
            'deadline' => [$this->outerRequest(body: $this->validBody(deadline: '2000-01-01T00:00:00+00:00')), null, 408, BridgeErrorKind::Timeout],
        ] as $case) {
            [$request, $authError, $status, $kind] = $case;
            $delegate = new RecordingDelegate();
            $handler = $this->handler($delegate, new RecordingAuthenticator($authError));

            $response = $handler->handle($request);
            $decoded = (new RemoteBridgeCodec())->decodeResult((string) $response->getBody());

            self::assertSame($status, $response->getStatusCode());
            self::assertSame('bridge_error', $decoded->outcome());
            self::assertSame($kind, $decoded->bridgeError()?->kind());
            self::assertTrue($decoded->isReusable());
            self::assertFalse($decoded->requiresQuarantine());
            self::assertSame(0, $delegate->calls);
        }
    }

    public function test_authenticator_is_mandatory_and_runs_before_delegated_execution(): void
    {
        $authenticator = new RecordingAuthenticator();
        $delegate = new RecordingDelegate(new TestResponse(204));
        $handler = $this->handler($delegate, $authenticator);

        $response = $handler->handle($this->outerRequest());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $authenticator->calls);
        self::assertSame(1, $delegate->calls);
        self::assertNotNull($delegate->request);
        self::assertSame('/delegated?ok=1', (string) $delegate->request->getUri());
        self::assertSame('PATCH', $delegate->request->getMethod());
        self::assertSame('{"name":"Evolve"}', (string) $delegate->request->getBody());
        self::assertSame(['application/json'], $delegate->request->getHeader('content-type'));
        self::assertSame([], $delegate->request->getHeader('x-secret'));
        self::assertInstanceOf(BridgeContext::class, $delegate->request->getAttribute(BridgeContext::class));
    }

    public function test_valid_delegated_execution_occurs_once_and_preserves_application_response_separately(): void
    {
        $delegate = new RecordingDelegate(new TestResponse(207, ['x-private' => ['hidden'], 'content-type' => ['application/json']], '{"done":true}'));
        $response = $this->handler($delegate)->handle($this->outerRequest());
        $decoded = (new RemoteBridgeCodec())->decodeResult((string) $response->getBody());

        self::assertSame(1, $delegate->calls);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application', $decoded->outcome());
        self::assertSame(207, $decoded->applicationStatus());
        self::assertSame(['content-type' => ['application/json']], $decoded->applicationHeaders());
        self::assertSame('{"done":true}', $decoded->applicationBody());
        self::assertSame('request-1', $decoded->requestIdentifier());
        self::assertSame('correlation-1', $decoded->correlationIdentifier());
        self::assertNull($decoded->bridgeError());
        self::assertTrue($decoded->isReusable());
        self::assertFalse($decoded->requiresQuarantine());
    }

    public function test_completed_quarantined_embedded_result_preserves_application_response_error_and_state(): void
    {
        $delegate = new RecordingDelegate(
            new TestResponse(207, ['content-type' => ['application/json']], '{"done":true}'),
            resetFailure: new RuntimeException('cleanup failed'),
        );

        $response = $this->handler($delegate)->handle($this->outerRequest());
        $decoded = (new RemoteBridgeCodec())->decodeResult((string) $response->getBody());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application', $decoded->outcome());
        self::assertSame(207, $decoded->applicationStatus());
        self::assertSame('{"done":true}', $decoded->applicationBody());
        self::assertSame(BridgeErrorKind::ResetOrQuarantine, $decoded->bridgeError()?->kind());
        self::assertFalse($decoded->isReusable());
        self::assertTrue($decoded->requiresQuarantine());
    }

    public function test_delegated_exception_becomes_application_response_without_trace_or_secret_details(): void
    {
        $delegate = new RecordingDelegate(new TestResponse(200), new RuntimeException('secret token stack trace'));
        $response = $this->handler($delegate)->handle($this->outerRequest());
        $body = (string) $response->getBody();
        $decoded = (new RemoteBridgeCodec())->decodeResult($body);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application', $decoded->outcome());
        self::assertSame(500, $decoded->applicationStatus());
        self::assertStringNotContainsString('secret', $body);
        self::assertStringNotContainsString('trace', strtolower($body));
        self::assertStringNotContainsString(__FILE__, $body);
    }

    public function test_response_factory_owns_outer_responses_and_handler_does_not_emit_output(): void
    {
        $factory = new RecordingResponseFactory();
        $handler = $this->handler(new RecordingDelegate(new TestResponse(200)), responseFactory: $factory);

        ob_start();
        $response = $handler->handle($this->outerRequest());
        $output = ob_get_clean();

        self::assertSame('', $output);
        self::assertSame([200], $factory->createdStatuses);
        self::assertSame(200, $response->getStatusCode());
    }

    private function handler(
        RecordingDelegate $delegate,
        ?RemoteBridgeAuthenticator $authenticator = null,
        ?RecordingResponseFactory $responseFactory = null,
    ): RemoteBridgeServerHandler {
        $responses = $responseFactory ?? new RecordingResponseFactory();
        $services = new ServiceRegistry();
        $services->freeze();
        $kernel = new HttpKernel(
            $delegate,
            new ExecutionOrchestrator($services),
        );
        $adapter = new EmbeddedBridgeAdapter(
            $kernel,
            new ExecutionOutcomeResponseResolver($responses),
            new AlwaysReady(),
        );

        return new RemoteBridgeServerHandler(
            $adapter,
            new RemoteBridgeCodec(),
            $authenticator ?? new RecordingAuthenticator(),
            $responses,
            new TestServerRequestFactory(),
            new TestStreamFactory(),
        );
    }

    private function outerRequest(
        string $method = 'POST',
        string $contentType = RemoteBridgeProtocol::MEDIA_TYPE,
        ?string $body = null,
    ): ServerRequestInterface {
        return new TestServerRequest($method, new TestUri('/bridge'), ['content-type' => [$contentType]], $body ?? $this->validBody());
    }

    private function validBody(?string $deadline = '2999-01-01T00:00:00+00:00'): string
    {
        return (new RemoteBridgeCodec())->encodeInvocation(new RemoteBridgeInvocation(
            operation: '/delegated',
            method: 'PATCH',
            target: '/delegated?ok=1',
            headers: ['content-type' => ['application/json'], 'x-secret' => ['nope']],
            body: '{"name":"Evolve"}',
            payload: ['safe' => true],
            requestIdentifier: 'request-1',
            correlationIdentifier: 'correlation-1',
            callerIdentifier: 'caller-1',
            deadline: $deadline,
            idempotencyKey: 'idem-1',
            trace: ['traceparent' => '00-00000000000000000000000000000000-0000000000000000-01'],
        ));
    }
}

final class RecordingAuthenticator implements RemoteBridgeAuthenticator
{
    public int $calls = 0;

    public function __construct(private readonly ?BridgeError $error = null) {}

    public function authenticate(ServerRequestInterface $request, RemoteBridgeInvocation $invocation): ?BridgeError
    {
        ++$this->calls;

        return $this->error;
    }
}

final class RecordingDelegate implements RequestHandlerInterface
{
    public int $calls = 0;
    public ?ServerRequestInterface $request = null;

    public function __construct(
        private readonly ?ResponseInterface $response = null,
        private readonly ?RuntimeException $failure = null,
        private readonly ?RuntimeException $resetFailure = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        ++$this->calls;
        $this->request = $request;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $scope = $request->getAttribute(ExecutionScope::class);

        if ($scope instanceof ExecutionScope && $this->resetFailure !== null) {
            $scope->registerResetParticipant('bridge-remote-test-reset', new FailingResetParticipant($this->resetFailure));
        }

        return $this->response ?? new TestResponse(200);
    }
}

final readonly class FailingResetParticipant implements ResetParticipant
{
    public function __construct(private RuntimeException $failure) {}

    public function reset(): void
    {
        throw $this->failure;
    }
}

final class AlwaysReady implements ReadinessCheck
{
    public function isReady(): bool
    {
        return true;
    }
}

final class RecordingResponseFactory implements ResponseFactoryInterface
{
    /**
     * @var list<int>
     */
    public array $createdStatuses = [];

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        $this->createdStatuses[] = $code;

        return new TestResponse($code);
    }
}

final class TestServerRequestFactory implements ServerRequestFactoryInterface
{
    /**
     * @param array<string, mixed> $serverParams
     */
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new TestServerRequest($method, $uri instanceof UriInterface ? $uri : new TestUri((string) $uri));
    }
}

final class TestStreamFactory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        return new TestStream($content);
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

final readonly class TestUri implements UriInterface
{
    public function __construct(private string $value) {}

    public function getScheme(): string
    {
        return '';
    }
    public function getAuthority(): string
    {
        return '';
    }
    public function getUserInfo(): string
    {
        return '';
    }
    public function getHost(): string
    {
        return '';
    }
    public function getPort(): ?int
    {
        return null;
    }
    public function getPath(): string
    {
        return parse_url($this->value, PHP_URL_PATH) ?: '';
    }
    public function getQuery(): string
    {
        return parse_url($this->value, PHP_URL_QUERY) ?: '';
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
        return new self($this->getPath() . '?' . $query);
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

class TestMessage implements MessageInterface
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        protected array $headers = [],
        protected StreamInterface $body = new TestStream(),
    ) {}

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
        return implode(', ', $this->getHeader($name));
    }
    public function withHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = (array) $value;
        return $clone;
    }
    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = array_merge($clone->getHeader($name), (array) $value);
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

final class TestServerRequest extends TestMessage implements ServerRequestInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $attributes = [];

    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        private readonly string $method,
        private readonly UriInterface $uri,
        array $headers = [],
        string $body = '',
    ) {
        parent::__construct(array_change_key_case($headers, CASE_LOWER), new TestStream($body));
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
        return new self($method, $this->uri, $this->headers, (string) $this->body);
    }
    public function getUri(): UriInterface
    {
        return $this->uri;
    }
    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        return new self($this->method, $uri, $this->headers, (string) $this->body);
    }
    /**
     * @return array<string, mixed>
     */
    public function getServerParams(): array
    {
        return [];
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
        return [];
    }
    /**
     * @param array<string, mixed> $query
     */
    public function withQueryParams(array $query): ServerRequestInterface
    {
        return $this;
    }
    /**
     * @return array<string, mixed>
     */
    public function getUploadedFiles(): array
    {
        return [];
    }
    /**
     * @param array<string, mixed> $uploadedFiles
     */
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        return $this;
    }
    /**
     * @return object|array<string, mixed>|null
     */
    public function getParsedBody(): object|array|null
    {
        return null;
    }
    /**
     * @param object|array<string, mixed>|null $data
     */
    public function withParsedBody($data): ServerRequestInterface
    {
        return $this;
    }
    /**
     * @return array<string, mixed>
     */
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

final class TestResponse extends TestMessage implements ResponseInterface
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        private int $status = 200,
        array $headers = [],
        string $body = '',
    ) {
        parent::__construct(array_change_key_case($headers, CASE_LOWER), new TestStream($body));
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

final class TestStream implements StreamInterface
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
