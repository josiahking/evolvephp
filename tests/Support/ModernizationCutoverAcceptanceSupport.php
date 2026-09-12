<?php

declare(strict_types=1);

namespace Tests\Support;

use BadMethodCallException;
use Evolve\Bridge\Contracts\BridgeContext;
use Evolve\Bridge\Laravel\LaravelBridgeAdapter;
use Evolve\Bridge\LegacyHttp\LegacyRemoteClient;
use Evolve\Bridge\LegacyHttp\LegacyRemoteClientAuthenticator;
use Evolve\Bridge\LegacyHttp\LegacyRemoteInvocation;
use Evolve\Bridge\LegacyHttp\LegacyRemoteProtocol;
use Evolve\Bridge\LegacyHttp\LegacyRemoteTransport;
use Evolve\Bridge\LegacyHttp\LegacyRemoteTransportResponse;
use Evolve\Bridge\Psr\EmbeddedBridgeAdapter;
use Evolve\Bridge\Remote\RemoteBridgeAuthenticator;
use Evolve\Bridge\Remote\RemoteBridgeCodec;
use Evolve\Bridge\Remote\RemoteBridgeInvocation;
use Evolve\Bridge\Remote\RemoteBridgeProtocol;
use Evolve\Bridge\Remote\RemoteBridgeServerHandler;
use Evolve\Bridge\Symfony\SymfonyBridgeAdapter;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Http\Health\ReadinessCheck;
use Evolve\Http\HttpKernel;
use Evolve\Http\Response\ExecutionOutcomeResponseResolver;
use Illuminate\Http\Request as LaravelRequest;
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
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

require_once dirname(__DIR__, 2) . '/compat/legacy-http-client/tests/bootstrap.php';

final class ModernizationCutoverAcceptanceSupport
{
    /**
     * @return array<string, mixed>
     */
    public static function scenario(): array
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/compat/legacy-http-client/tests/fixtures/modernization-cutover.json');

        if (! is_string($json)) {
            throw new RuntimeException('Modernization cutover fixture is not readable.');
        }

        $scenario = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($scenario)) {
            throw new RuntimeException('Modernization cutover fixture must decode to an object.');
        }

        return $scenario;
    }

    /**
     * @param array<string, mixed> $scenario
     *
     * @return array<string, mixed>
     */
    public static function invokeLaravel(array $scenario): array
    {
        $handler = new InvoiceSummaryHandler();
        $adapter = new LaravelBridgeAdapter(self::embedded($handler), new PsrServerRequestFactory(), new PsrStreamFactory());
        $request = LaravelRequest::create(
            $scenario['target'],
            $scenario['method'],
            $scenario['payload'],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_TRACEPARENT' => $scenario['trace']['traceparent'],
                'HTTP_X_HOST_ONLY_STATE' => 'must-not-forward',
            ],
            $scenario['body'],
        );

        $result = $adapter->invoke($request, self::context($scenario));
        $response = $result->response();

        if ($response === null) {
            throw new RuntimeException('Laravel delegation did not return an application response.');
        }

        return self::delegationResult($handler, (string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $scenario
     *
     * @return array<string, mixed>
     */
    public static function invokeSymfony(array $scenario): array
    {
        $handler = new InvoiceSummaryHandler();
        $adapter = new SymfonyBridgeAdapter(self::embedded($handler), new PsrServerRequestFactory(), new PsrStreamFactory());
        $request = SymfonyRequest::create(
            $scenario['target'],
            $scenario['method'],
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_TRACEPARENT' => $scenario['trace']['traceparent'],
                'HTTP_X_HOST_ONLY_STATE' => 'must-not-forward',
            ],
            $scenario['body'],
        );

        $request->request->replace($scenario['payload']);

        $result = $adapter->invoke($request, self::context($scenario));
        $response = $result->response();

        if ($response === null) {
            throw new RuntimeException('Symfony delegation did not return an application response.');
        }

        return self::delegationResult($handler, (string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $scenario
     *
     * @return array<string, mixed>
     */
    public static function invokeLegacyRemote(array $scenario): array
    {
        $handler = new InvoiceSummaryHandler();
        $server = new RemoteBridgeServerHandler(
            self::embedded($handler),
            new RemoteBridgeCodec(),
            new AcceptingRemoteAuthenticator(),
            new PsrResponseFactory(),
            new PsrServerRequestFactory(),
            new PsrStreamFactory(),
        );
        $transport = new InMemoryLegacyRemoteTransport($server);
        $client = new LegacyRemoteClient('https://bridge.example.test/evolve-remote', $transport, new NullLegacyAuthenticator());
        $result = $client->invoke(new LegacyRemoteInvocation(
            $scenario['operation'],
            $scenario['method'],
            $scenario['target'],
            $scenario['headers'],
            $scenario['body'],
            $scenario['payload'],
            $scenario['request_id'],
            $scenario['correlation_id'],
            $scenario['caller_id'],
            $scenario['principal_id'],
            $scenario['tenant_id'],
            $scenario['locale'],
            $scenario['timezone'],
            $scenario['deadline'],
            $scenario['idempotency_key'],
            $scenario['trace'],
        ));

        if (! $result->received() || $result->outcome() !== 'application') {
            throw new RuntimeException('Legacy remote delegation did not return an application response.');
        }

        return self::delegationResult($handler, (string) $result->applicationBody());
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private static function context(array $scenario): BridgeContext
    {
        return new BridgeContext(
            $scenario['request_id'],
            $scenario['correlation_id'],
            $scenario['principal_id'],
            $scenario['tenant_id'],
            $scenario['locale'],
            $scenario['timezone'],
        );
    }

    private static function embedded(InvoiceSummaryHandler $handler): EmbeddedBridgeAdapter
    {
        $services = new ServiceRegistry();
        $services->freeze();
        $responses = new PsrResponseFactory();

        return new EmbeddedBridgeAdapter(
            new HttpKernel($handler, new ExecutionOrchestrator($services)),
            new ExecutionOutcomeResponseResolver($responses),
            new ReadyCheck(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function delegationResult(InvoiceSummaryHandler $handler, string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Delegated response body must decode to an object.');
        }

        return [
            'execution_count' => $handler->calls,
            'operation' => $handler->operation,
            'context' => $handler->context,
            'forwarded_headers' => $handler->forwardedHeaders,
            'result' => $decoded,
        ];
    }
}

final class InvoiceSummaryHandler implements RequestHandlerInterface
{
    public int $calls = 0;
    public string $operation = '';

    /**
     * @var array<string, string|null>
     */
    public array $context = [];

    /**
     * @var array<string, list<string>>
     */
    public array $forwardedHeaders = [];

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        ++$this->calls;
        $context = $request->getAttribute(BridgeContext::class);

        if (! $context instanceof BridgeContext) {
            throw new RuntimeException('Bridge context was not attached.');
        }

        $payload = $request->getParsedBody();
        $remotePayload = $request->getAttribute('evolve.bridge.remote.payload');

        if (is_array($remotePayload)) {
            $payload = $remotePayload;
        }

        if (! is_array($payload)) {
            $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Invoice summary payload must be an array.');
        }

        $remoteInvocation = $request->getAttribute(RemoteBridgeInvocation::class);
        $this->operation = $remoteInvocation instanceof RemoteBridgeInvocation
            ? $remoteInvocation->operation()
            : 'billing.invoice-summary';
        $this->context = [
            'request_id' => $context->requestIdentifier(),
            'correlation_id' => $context->correlationIdentifier(),
            'principal_id' => $context->principalIdentifier(),
            'tenant_id' => $context->tenantIdentifier(),
        ];
        $this->forwardedHeaders = $request->getHeaders();

        $result = [
            'capability' => 'billing.invoice-summary',
            'invoice_count' => count($payload['invoice_ids'] ?? []),
            'total_amount' => array_sum($payload['amounts'] ?? []),
            'currency' => $payload['currency'] ?? '',
            'principal_id' => $context->principalIdentifier(),
            'tenant_id' => $context->tenantIdentifier(),
        ];

        return new PsrResponse(200, ['content-type' => ['application/json']], json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}

final class ReadyCheck implements ReadinessCheck
{
    public function isReady(): bool
    {
        return true;
    }
}

final class AcceptingRemoteAuthenticator implements RemoteBridgeAuthenticator
{
    public function authenticate(ServerRequestInterface $request, RemoteBridgeInvocation $invocation): ?\Evolve\Bridge\Contracts\BridgeError
    {
        return null;
    }
}

final class NullLegacyAuthenticator implements LegacyRemoteClientAuthenticator
{
    public function authenticationHeaders(LegacyRemoteInvocation $invocation): array
    {
        return [];
    }
}

final class InMemoryLegacyRemoteTransport implements LegacyRemoteTransport
{
    public function __construct(private RemoteBridgeServerHandler $server) {}

    public function send(string $method, string $endpoint, array $headers, string $body, float $connectTimeoutSeconds, float $requestTimeoutSeconds): LegacyRemoteTransportResponse
    {
        $request = (new PsrServerRequest($method, new PsrUri($endpoint), $headers, new PsrStream($body)))
            ->withHeader('content-type', [LegacyRemoteProtocol::MEDIA_TYPE]);
        $response = $this->server->handle($request);

        return new LegacyRemoteTransportResponse(
            $response->getStatusCode(),
            $response->getHeaders(),
            (string) $response->getBody(),
        );
    }
}

final class PsrResponseFactory implements ResponseFactoryInterface
{
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new PsrResponse($code, [], '', $reasonPhrase);
    }
}

final class PsrServerRequestFactory implements ServerRequestFactoryInterface
{
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new PsrServerRequest($method, $uri instanceof UriInterface ? $uri : new PsrUri((string) $uri), [], new PsrStream(), $serverParams);
    }
}

final class PsrStreamFactory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        return new PsrStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        throw new BadMethodCallException('File streams are not used by modernization cutover acceptance tests.');
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return new PsrStream((string) stream_get_contents($resource));
    }
}

class PsrMessage implements MessageInterface
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        protected array $headers = [],
        protected StreamInterface $body = new PsrStream(),
        private string $protocolVersion = '1.1',
    ) {
        $this->headers = self::normalizeHeaders($headers);
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;

        return $clone;
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
        $clone->headers[strtolower($name)] = is_array($value) ? array_values($value) : [(string) $value];

        return $clone;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = array_merge($clone->getHeader($name), is_array($value) ? array_values($value) : [(string) $value]);

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

    /**
     * @param array<string, list<string>> $headers
     *
     * @return array<string, list<string>>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            $normalized[strtolower($name)] = array_values($values);
        }

        ksort($normalized);

        return $normalized;
    }
}

final class PsrServerRequest extends PsrMessage implements ServerRequestInterface
{
    /**
     * @param array<string, list<string>> $headers
     * @param array<string, mixed> $serverParams
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed>|object|null $parsedBody
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private string $method,
        private UriInterface $uri,
        array $headers = [],
        StreamInterface $body = new PsrStream(),
        private array $serverParams = [],
        private array $queryParams = [],
        private mixed $parsedBody = null,
        private array $attributes = [],
    ) {
        parent::__construct($headers, $body);
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
        return strtoupper($this->method);
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

    public function getServerParams(): array
    {
        return $this->serverParams;
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
        return $this->queryParams;
    }

    public function withQueryParams(array $query): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->queryParams = $query;

        return $clone;
    }

    public function getUploadedFiles(): array
    {
        return [];
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        return $this;
    }

    public function getParsedBody(): mixed
    {
        return $this->parsedBody;
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->parsedBody = $data;

        return $clone;
    }

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

final class PsrResponse extends PsrMessage implements ResponseInterface
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        private int $statusCode = 200,
        array $headers = [],
        string $body = '',
        private string $reasonPhrase = '',
    ) {
        parent::__construct($headers, new PsrStream($body));
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->reasonPhrase = $reasonPhrase;

        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }
}

final class PsrUri implements UriInterface
{
    public function __construct(private string $value) {}

    public function getScheme(): string
    {
        return (string) parse_url($this->value, PHP_URL_SCHEME);
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
        return (string) parse_url($this->value, PHP_URL_HOST);
    }

    public function getPort(): ?int
    {
        return null;
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
        return new self($path . ($this->getQuery() === '' ? '' : '?' . $this->getQuery()));
    }

    public function withQuery(string $query): UriInterface
    {
        return new self($this->getPath() . ($query === '' ? '' : '?' . $query));
    }

    public function withFragment(string $fragment): UriInterface
    {
        return $this;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

final class PsrStream implements StreamInterface
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
