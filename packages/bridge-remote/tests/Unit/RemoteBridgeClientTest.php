<?php

declare(strict_types=1);

namespace Evolve\Bridge\Remote\Tests\Unit;

use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Contracts\BridgeErrorKind;
use Evolve\Bridge\Remote\RemoteBridgeClient;
use Evolve\Bridge\Remote\RemoteBridgeClientAuthenticator;
use Evolve\Bridge\Remote\RemoteBridgeClientResult;
use Evolve\Bridge\Remote\RemoteBridgeCodec;
use Evolve\Bridge\Remote\RemoteBridgeInvocation;
use Evolve\Bridge\Remote\RemoteBridgeProtocol;
use Evolve\Bridge\Remote\RemoteBridgeResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

final class RemoteBridgeClientTest extends TestCase
{
    public function testClientResultSeparatesReceivedProtocolResultsFromLocalFailures(): void
    {
        $protocolResult = RemoteBridgeResult::applicationResponse('request-1', 'correlation-1', 204, [], null);
        $received = RemoteBridgeClientResult::received($protocolResult);

        self::assertSame($protocolResult, $received->result());
        self::assertNull($received->error());

        $error = new BridgeError(BridgeErrorKind::Transport, 'remote_bridge_transport_failed', 'Remote Bridge transport failed.', false);
        $failure = RemoteBridgeClientResult::failure($error);

        self::assertNull($failure->result());
        self::assertSame($error, $failure->error());
    }

    #[DataProvider('invalidEndpoints')]
    public function testConfiguredEndpointMustBeTrustedAbsoluteHttpEndpoint(string $endpoint): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client(endpoint: $endpoint);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEndpoints(): iterable
    {
        yield 'relative' => ['/bridge'];
        yield 'scheme' => ['ftp://example.test/bridge'];
        yield 'host' => ['https:///bridge'];
        yield 'credentials' => ['https://user:secret@example.test/bridge'];
        yield 'fragment' => ['https://example.test/bridge#frag'];
    }

    public function testSuccessfulInvocationBuildsOneProtocolRequestAndReturnsDecodedApplicationResult(): void
    {
        $codec = new RemoteBridgeCodec();
        $http = new RecordingHttpClient(new ClientTestResponse(
            200,
            ['content-type' => [RemoteBridgeProtocol::MEDIA_TYPE . '; charset=utf-8']],
            $codec->encodeResult(RemoteBridgeResult::applicationResponse('request-1', 'correlation-1', 202, ['content-type' => ['application/json']], '{"ok":true}')),
        ));
        $authenticator = new RecordingClientAuthenticator(['authorization' => ['Bearer client-secret']]);
        $client = $this->client(
            endpoint: 'http://127.0.0.1:8080/bridge',
            http: $http,
            codec: $codec,
            authenticator: $authenticator,
        );

        $clientResult = $client->invoke($this->invocation(target: '/delegated?ok=1'));

        self::assertSame(1, $http->calls);
        self::assertSame(1, $authenticator->calls);
        self::assertNotNull($http->request);
        self::assertSame('POST', $http->request->getMethod());
        self::assertSame('http://127.0.0.1:8080/bridge', (string) $http->request->getUri());
        self::assertSame(RemoteBridgeProtocol::MEDIA_TYPE, $http->request->getHeaderLine('content-type'));
        self::assertSame(RemoteBridgeProtocol::MEDIA_TYPE, $http->request->getHeaderLine('accept'));
        self::assertSame('Bearer client-secret', $http->request->getHeaderLine('authorization'));

        $outer = $codec->decodeInvocation((string) $http->request->getBody());
        self::assertSame('/delegated?ok=1', $outer->target());
        self::assertSame('request-1', $outer->requestIdentifier());

        $received = $clientResult->result();
        self::assertNull($clientResult->error());
        self::assertNotNull($received);
        self::assertSame('application', $received->outcome());
        self::assertSame(202, $received->applicationStatus());
        self::assertSame('{"ok":true}', $received->applicationBody());
    }

    public function testRemoteBridgeErrorResultAndHttpErrorEnvelopeAreReturnedAsReceived(): void
    {
        foreach ([401, 503] as $status) {
            $codec = new RemoteBridgeCodec();
            $remoteError = RemoteBridgeResult::error(
                'request-1',
                'correlation-1',
                $status,
                new BridgeError(BridgeErrorKind::Authentication, 'auth_failed', 'Authentication failed.', false),
            );
            $client = $this->client(http: new RecordingHttpClient(new ClientTestResponse($status, ['content-type' => [RemoteBridgeProtocol::MEDIA_TYPE]], $codec->encodeResult($remoteError))));

            $result = $client->invoke($this->invocation());

            $received = $result->result();
            self::assertNull($result->error());
            self::assertNotNull($received);
            self::assertSame($status, $received->outerStatus());
            self::assertSame(BridgeErrorKind::Authentication, $received->bridgeError()?->kind());
        }
    }

    public function testExpiredDeadlineFailsBeforeTransportIsCalled(): void
    {
        $http = new RecordingHttpClient(new ClientTestResponse());
        $result = $this->client(http: $http)->invoke($this->invocation(deadline: '2000-01-01T00:00:00+00:00'));

        self::assertSame(0, $http->calls);
        $error = $result->error();
        self::assertNotNull($error);
        self::assertSame(BridgeErrorKind::Timeout, $error->kind());
        self::assertFalse($error->isRetryable());
    }

    public function testOutboundEncodedBodyCannotExceedProtocolLimit(): void
    {
        $http = new RecordingHttpClient(new ClientTestResponse());
        $result = $this->client(http: $http)->invoke($this->invocation(body: str_repeat('x', RemoteBridgeProtocol::MAX_BODY_BYTES + 1)));

        self::assertSame(0, $http->calls);
        self::assertSame(BridgeErrorKind::Protocol, $result->error()?->kind());
    }

    /**
     * @param array<string, list<string>> $headers
     */
    #[DataProvider('invalidAuthenticationHeaders')]
    public function testAuthenticatorHeadersAreValidatedAndCannotOverrideProtocolOrHopByHopHeaders(array $headers): void
    {
        $http = new RecordingHttpClient(new ClientTestResponse());
        $result = $this->client(
            http: $http,
            authenticator: new RecordingClientAuthenticator($headers),
        )->invoke($this->invocation());

        self::assertSame(0, $http->calls);
        self::assertSame(BridgeErrorKind::Authentication, $result->error()?->kind());
    }

    /**
     * @return iterable<string, array{array<string, list<string>>}>
     */
    public static function invalidAuthenticationHeaders(): iterable
    {
        yield 'invalid name' => [['bad header' => ['value']]];
        yield 'invalid value' => [['x-service-auth' => ["bad\nvalue"]]];
        yield 'content type override' => [['content-type' => ['text/plain']]];
        yield 'host override' => [['host' => ['evil.test']]];
        yield 'protocol identity override' => [['x-request-id' => ['other']]];
        yield 'proxy transport override' => [['proxy-authorization' => ['secret']]];
    }

    public function testAuthenticatorExceptionIsSafeAndDoesNotExposeSecretDetails(): void
    {
        $result = $this->client(
            authenticator: new ThrowingClientAuthenticator(new RuntimeException('secret token leaked')),
        )->invoke($this->invocation());

        $error = $result->error();
        self::assertNotNull($error);
        self::assertSame(BridgeErrorKind::Authentication, $error->kind());
        self::assertStringNotContainsString('secret', strtolower($error->message()));
        self::assertStringNotContainsString('token', strtolower($error->message()));
    }

    #[DataProvider('invalidResponses')]
    public function testInvalidProtocolResponsesBecomeSafeProtocolFailures(ResponseInterface $response, string $expectedCode): void
    {
        $result = $this->client(http: new RecordingHttpClient($response))->invoke($this->invocation());

        self::assertNull($result->result());
        $error = $result->error();
        self::assertNotNull($error);
        self::assertSame(BridgeErrorKind::Protocol, $error->kind());
        self::assertSame($expectedCode, $error->code());
    }

    /**
     * @return iterable<string, array{ResponseInterface, string}>
     */
    public static function invalidResponses(): iterable
    {
        $codec = new RemoteBridgeCodec();

        yield 'redirect' => [new ClientTestResponse(302, ['content-type' => [RemoteBridgeProtocol::MEDIA_TYPE]], ''), 'remote_bridge_redirect_response'];
        yield 'media type' => [new ClientTestResponse(200, ['content-type' => ['text/plain']], '{}'), 'remote_bridge_unsupported_media_type'];
        yield 'oversized known body' => [new ClientTestResponse(200, ['content-type' => [RemoteBridgeProtocol::MEDIA_TYPE]], str_repeat('x', RemoteBridgeProtocol::MAX_BODY_BYTES + 1)), 'remote_bridge_response_too_large'];
        yield 'oversized unknown body' => [new ClientTestResponse(200, ['content-type' => [RemoteBridgeProtocol::MEDIA_TYPE]], str_repeat('x', RemoteBridgeProtocol::MAX_BODY_BYTES + 1), null, false), 'remote_bridge_response_too_large'];
        yield 'malformed' => [new ClientTestResponse(200, ['content-type' => [RemoteBridgeProtocol::MEDIA_TYPE]], '{"nope"'), 'remote_bridge_invalid_response'];
        yield 'status mismatch' => [new ClientTestResponse(201, ['content-type' => [RemoteBridgeProtocol::MEDIA_TYPE]], $codec->encodeResult(RemoteBridgeResult::applicationResponse('request-1', 'correlation-1', 200, [], null))), 'remote_bridge_status_mismatch'];
        yield 'request mismatch' => [new ClientTestResponse(200, ['content-type' => [RemoteBridgeProtocol::MEDIA_TYPE]], $codec->encodeResult(RemoteBridgeResult::applicationResponse('other-request', 'correlation-1', 200, [], null))), 'remote_bridge_request_mismatch'];
        yield 'correlation mismatch' => [new ClientTestResponse(200, ['content-type' => [RemoteBridgeProtocol::MEDIA_TYPE]], $codec->encodeResult(RemoteBridgeResult::applicationResponse('request-1', 'other-correlation', 200, [], null))), 'remote_bridge_correlation_mismatch'];
    }

    public function testPsr18RequestExceptionBecomesSafeTransportFailure(): void
    {
        $result = $this->client(http: new RecordingHttpClient(failure: new ClientTestRequestException('secret request construction detail')))->invoke($this->invocation());

        $error = $result->error();
        self::assertNotNull($error);
        self::assertSame(BridgeErrorKind::Transport, $error->kind());
        self::assertStringNotContainsString('secret', strtolower($error->message()));
    }

    public function testPsr18NetworkFailureIsConservativeUncertainOutcomeAndNotRetried(): void
    {
        $http = new RecordingHttpClient(failure: new ClientTestNetworkException('secret socket detail'));
        $result = $this->client(http: $http)->invoke($this->invocation());

        self::assertSame(1, $http->calls);
        $error = $result->error();
        self::assertNotNull($error);
        self::assertSame(BridgeErrorKind::UncertainOutcome, $error->kind());
        self::assertFalse($error->isRetryable());
        self::assertStringNotContainsString('secret', strtolower($error->message()));
    }

    public function testOtherPsr18FailuresAreSafeBoundedTransportErrorsWithoutRetry(): void
    {
        $http = new RecordingHttpClient(failure: new ClientTestClientException('secret transport detail'));
        $result = $this->client(http: $http)->invoke($this->invocation());

        self::assertSame(1, $http->calls);
        $error = $result->error();
        self::assertNotNull($error);
        self::assertSame(BridgeErrorKind::Transport, $error->kind());
        self::assertFalse($error->isRetryable());
        self::assertStringNotContainsString('secret', strtolower($error->message()));
    }

    private function client(
        string $endpoint = 'https://remote.example.test/bridge',
        ?RecordingHttpClient $http = null,
        ?RemoteBridgeCodec $codec = null,
        ?RemoteBridgeClientAuthenticator $authenticator = null,
    ): RemoteBridgeClient {
        return new RemoteBridgeClient(
            $endpoint,
            $http ?? new RecordingHttpClient(new ClientTestResponse(200, ['content-type' => [RemoteBridgeProtocol::MEDIA_TYPE]], (new RemoteBridgeCodec())->encodeResult(RemoteBridgeResult::applicationResponse('request-1', 'correlation-1', 200, [], null)))),
            new ClientTestRequestFactory(),
            new ClientTestStreamFactory(),
            $codec ?? new RemoteBridgeCodec(),
            $authenticator ?? new RecordingClientAuthenticator(['x-service-auth' => ['signed']]),
        );
    }

    private function invocation(?string $deadline = '2999-01-01T00:00:00+00:00', string $target = '/delegated', string $body = '{"ok":true}'): RemoteBridgeInvocation
    {
        return new RemoteBridgeInvocation(
            operation: 'delegated.operation',
            method: 'PATCH',
            target: $target,
            headers: ['content-type' => ['application/json']],
            body: $body,
            payload: ['safe' => true],
            requestIdentifier: 'request-1',
            correlationIdentifier: 'correlation-1',
            callerIdentifier: 'caller-1',
            deadline: $deadline,
            idempotencyKey: 'idem-1',
            trace: ['traceparent' => '00-00000000000000000000000000000000-0000000000000000-01'],
        );
    }
}

final class RecordingClientAuthenticator implements RemoteBridgeClientAuthenticator
{
    public int $calls = 0;

    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(private readonly array $headers) {}

    public function authenticationHeaders(RequestInterface $request, RemoteBridgeInvocation $invocation): array
    {
        ++$this->calls;

        return $this->headers;
    }
}

final class ThrowingClientAuthenticator implements RemoteBridgeClientAuthenticator
{
    public function __construct(private readonly RuntimeException $failure) {}

    public function authenticationHeaders(RequestInterface $request, RemoteBridgeInvocation $invocation): array
    {
        throw $this->failure;
    }
}

final class RecordingHttpClient implements ClientInterface
{
    public int $calls = 0;
    public ?RequestInterface $request = null;

    public function __construct(
        private readonly ?ResponseInterface $response = null,
        private readonly ?ClientExceptionInterface $failure = null,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        ++$this->calls;
        $this->request = $request;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->response ?? new ClientTestResponse();
    }
}

final class ClientTestRequestFactory implements RequestFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new ClientTestRequest($method, $uri instanceof UriInterface ? $uri : new ClientTestUri((string) $uri));
    }
}

final class ClientTestStreamFactory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        return new ClientTestStream($content);
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

final readonly class ClientTestUri implements UriInterface
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
        return (string) parse_url($this->value, PHP_URL_FRAGMENT);
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

class ClientTestMessage implements MessageInterface
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        protected array $headers = [],
        protected StreamInterface $body = new ClientTestStream(),
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
        $clone->headers[strtolower($name)] = array_values((array) $value);

        return $clone;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = array_merge($clone->getHeader($name), array_values((array) $value));

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

final class ClientTestRequest extends ClientTestMessage implements RequestInterface
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        private string $method,
        private UriInterface $uri,
        array $headers = [],
        string $body = '',
    ) {
        parent::__construct(array_change_key_case($headers, CASE_LOWER), new ClientTestStream($body));
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

final class ClientTestResponse extends ClientTestMessage implements ResponseInterface
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        private int $status = 200,
        array $headers = [],
        string $body = '',
        private readonly ?int $bodySize = null,
        private readonly bool $bodySizeKnown = true,
    ) {
        parent::__construct(array_change_key_case($headers, CASE_LOWER), new ClientTestStream($body, $bodySize, $bodySizeKnown));
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

final class ClientTestStream implements StreamInterface
{
    public function __construct(private string $content = '', private ?int $size = null, private bool $sizeKnown = true) {}

    public function __toString(): string
    {
        return $this->content;
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        if (!$this->sizeKnown) {
            return null;
        }

        return $this->size ?? strlen($this->content);
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

final class ClientTestRequestException extends RuntimeException implements RequestExceptionInterface
{
    public function getRequest(): RequestInterface
    {
        return new ClientTestRequest('POST', new ClientTestUri('https://remote.example.test/bridge'));
    }
}

final class ClientTestNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function getRequest(): RequestInterface
    {
        return new ClientTestRequest('POST', new ClientTestUri('https://remote.example.test/bridge'));
    }
}

final class ClientTestClientException extends RuntimeException implements ClientExceptionInterface {}
