<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Http;

use BadMethodCallException;
use Evolve\Observe\Exception\OpenTelemetryContextDetachFailed;
use Evolve\Observe\Http\HttpServerTraceInstrumentation;
use Evolve\Observe\Http\Internal\HttpServerSpanState;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

final class HttpServerTraceInstrumentationTest extends TestCase
{
    public function testDisabledInstrumentationIsInertAndPreservesOriginalRequest(): void
    {
        $instrumentation = new HttpServerTraceInstrumentation(OpenTelemetryComposition::disabled());
        $request = TraceServerRequest::get('/users/123')->withHeader('traceparent', $this->traceparent());
        $response = new TraceResponse(204);
        $handledRequest = null;

        $actual = $instrumentation->trace($request, static function (ServerRequestInterface $operationRequest) use (&$handledRequest, $response): ResponseInterface {
            $handledRequest = $operationRequest;
            self::assertFalse(Span::getCurrent()->getContext()->isValid());

            return $response;
        });

        $this->assertSame($response, $actual);
        $this->assertSame($request, $handledRequest);
        $this->assertNull($request->getAttribute(HttpServerSpanState::class));
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testMeterOnlyCompositionLeavesHttpTracingInert(): void
    {
        $instrumentation = new HttpServerTraceInstrumentation(new OpenTelemetryComposition(
            enabled: true,
            meterProvider: new NoopMeterProvider(),
            resource: $this->resource(),
        ));
        $request = TraceServerRequest::get('/meter-only');
        $response = new TraceResponse(204);
        $handledRequest = null;

        $actual = $instrumentation->trace($request, static function (ServerRequestInterface $operationRequest) use (&$handledRequest, $response): ResponseInterface {
            $handledRequest = $operationRequest;

            return $response;
        });

        $this->assertSame($response, $actual);
        $this->assertSame($request, $handledRequest);
        $this->assertNull($request->getAttribute(HttpServerSpanState::class));
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testValidTraceContextBecomesRemoteParentAndBaggageIsIgnored(): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();
        $traceId = str_repeat('1', 32);
        $parentSpanId = str_repeat('2', 16);

        $instrumentation->trace(
            TraceServerRequest::get('/users/123')
                ->withHeader('traceparent', '00-' . $traceId . '-' . $parentSpanId . '-01')
                ->withHeader('tracestate', 'vendor=value')
                ->withHeader('baggage', 'tenant=secret-tenant'),
            static fn(): ResponseInterface => new TraceResponse(200),
        );

        $span = $this->singleSpan($exporter);
        $attributes = $span->getAttributes()->toArray();

        $this->assertSame($traceId, $span->getTraceId());
        $this->assertSame($parentSpanId, $span->getParentSpanId());
        $this->assertSame('vendor=value', (string) $span->getParentContext()->getTraceState());
        $this->assertSame(SpanKind::KIND_SERVER, $span->getKind());
        $this->assertSame('GET', $span->getName());
        $this->assertStringNotContainsString('secret-tenant', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    public function testStableUrlServerAttributesUsePsrUriPathAndSchemeWithoutQuery(): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();

        $instrumentation->trace(
            TraceServerRequest::get('/users/123?token=secret')->withUri(TraceUri::fromTarget('/users/123?token=secret')->withScheme('https')),
            static fn(): ResponseInterface => new TraceResponse(200),
        );

        $attributes = $this->singleSpan($exporter)->getAttributes();
        $encodedAttributes = json_encode($attributes->toArray(), JSON_THROW_ON_ERROR);

        $this->assertSame('/users/123', $attributes->get(UrlAttributes::URL_PATH));
        $this->assertSame('https', $attributes->get(UrlAttributes::URL_SCHEME));
        $this->assertFalse($attributes->has(UrlAttributes::URL_QUERY));
        $this->assertFalse($attributes->has(UrlAttributes::URL_FULL));
        $this->assertStringNotContainsString('token=secret', $encodedAttributes);
        $this->assertStringNotContainsString('secret', $encodedAttributes);
    }

    public function testAbsentOrInvalidTraceContextDoesNotBreakApplicationHandling(): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();

        $first = $instrumentation->trace(TraceServerRequest::get('/first'), static fn(): ResponseInterface => new TraceResponse(200));
        $second = $instrumentation->trace(
            TraceServerRequest::get('/second')->withHeader('traceparent', 'not-valid'),
            static fn(): ResponseInterface => new TraceResponse(200),
        );

        $spans = $exporter->getSpans();

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertCount(2, $spans);
        $this->assertNotSame($spans[0]->getTraceId(), $spans[1]->getTraceId());
        $this->assertSame(str_repeat('0', 16), $spans[0]->getParentSpanId());
        $this->assertSame(str_repeat('0', 16), $spans[1]->getParentSpanId());
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testServerSpanIsActiveBeforeCallerAndNestedWrapperDoesNotDuplicateIt(): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();
        $stateSeenByOuter = null;
        $stateSeenByNested = null;

        $instrumentation->trace(
            TraceServerRequest::get('/users/123'),
            function (ServerRequestInterface $instrumentedRequest) use ($instrumentation, &$stateSeenByOuter, &$stateSeenByNested): ResponseInterface {
                $stateSeenByOuter = $instrumentedRequest->getAttribute(HttpServerSpanState::class);
                self::assertInstanceOf(HttpServerSpanState::class, $stateSeenByOuter);
                self::assertTrue(Span::getCurrent()->getContext()->isValid());

                return $instrumentation->trace(
                    $instrumentedRequest,
                    static function (ServerRequestInterface $nestedRequest) use (&$stateSeenByNested): ResponseInterface {
                        $stateSeenByNested = $nestedRequest->getAttribute(HttpServerSpanState::class);
                        self::assertTrue(Span::getCurrent()->getContext()->isValid());

                        return new TraceResponse(200);
                    },
                );
            },
        );

        $this->assertSame($stateSeenByOuter, $stateSeenByNested);
        $this->assertCount(1, $exporter->getSpans());
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function responseStatusProvider(): iterable
    {
        yield 'success' => [200, StatusCode::STATUS_UNSET];
        yield 'redirect' => [302, StatusCode::STATUS_UNSET];
        yield 'normal client error' => [404, StatusCode::STATUS_UNSET];
        yield 'server error' => [503, StatusCode::STATUS_ERROR];
    }

    #[DataProvider('responseStatusProvider')]
    public function testResponseStatusSemanticsAreBounded(int $statusCode, string $expectedSpanStatus): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();

        $instrumentation->trace(TraceServerRequest::get('/status'), static fn(): ResponseInterface => new TraceResponse($statusCode));

        $span = $this->singleSpan($exporter);
        $attributes = $span->getAttributes();

        $this->assertSame($statusCode, $attributes->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame($expectedSpanStatus, $span->getStatus()->getCode());
        if ($statusCode >= 500) {
            $this->assertSame((string) $statusCode, $attributes->get(ErrorAttributes::ERROR_TYPE));
        } else {
            $this->assertFalse($attributes->has(ErrorAttributes::ERROR_TYPE));
        }
    }

    public function testThrownApplicationExceptionIsPreservedWithoutMessageOrStackTelemetry(): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();
        $throwable = new RuntimeException('secret-token-value');

        try {
            $instrumentation->trace(TraceServerRequest::get('/fail'), static function () use ($throwable): ResponseInterface {
                throw $throwable;
            });
            self::fail('Application throwable should be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($throwable, $caught);
        }

        $span = $this->singleSpan($exporter);
        $attributes = $span->getAttributes()->toArray();
        $events = $span->getEvents();

        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(RuntimeException::class, $attributes[ErrorAttributes::ERROR_TYPE]);
        $this->assertArrayNotHasKey('exception.message', $attributes);
        $this->assertArrayNotHasKey('exception.stacktrace', $attributes);
        $this->assertStringNotContainsString('secret-token-value', json_encode($attributes, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret-token-value', json_encode($events, JSON_THROW_ON_ERROR));
    }

    public function testSensitiveRequestDataIsNotCaptured(): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();

        $instrumentation->trace(
            TraceServerRequest::get('/users/123?token=query-secret')
                ->withHeader('authorization', 'Bearer auth-secret')
                ->withHeader('cookie', 'session=cookie-secret')
                ->withHeader('x-custom-secret', 'header-secret')
                ->withParsedBody(['password' => 'body-secret'])
                ->withCookieParams(['session' => 'cookie-secret'])
                ->withQueryParams(['token' => 'query-secret']),
            static fn(): ResponseInterface => new TraceResponse(200),
        );

        $encodedAttributes = json_encode($this->singleSpan($exporter)->getAttributes()->toArray(), JSON_THROW_ON_ERROR);

        foreach (['query-secret', 'auth-secret', 'cookie-secret', 'header-secret', 'body-secret', 'authorization', 'cookie', 'x-custom-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $encodedAttributes);
        }
    }

    public function testNonZeroDetachProducesTypedFailureAndEndsSpanOnce(): void
    {
        $span = new CountingTraceSpan(new TraceScope(ScopeInterface::MISMATCH));
        $instrumentation = new HttpServerTraceInstrumentation(new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new TraceTracerProvider($span),
            resource: $this->resource(),
        ));

        $this->expectException(OpenTelemetryContextDetachFailed::class);

        try {
            $instrumentation->trace(TraceServerRequest::get('/cleanup'), static fn(): ResponseInterface => new TraceResponse(200));
        } finally {
            $this->assertSame(1, $span->endCalls);
        }
    }

    public function testRecognizedUppercaseMethodsUseTheSameSemanticMethodAndSpanName(): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();

        $instrumentation->trace(TraceServerRequest::method('POST', '/method'), static fn(): ResponseInterface => new TraceResponse(200));

        $span = $this->singleSpan($exporter);
        $attributes = $span->getAttributes();

        $this->assertSame('POST', $span->getName());
        $this->assertSame('POST', $attributes->get(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertFalse($attributes->has(HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL));
    }

    public function testCanonicalizedRecognizedMethodsRecordOriginalMethod(): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();

        $instrumentation->trace(TraceServerRequest::method('get', '/method'), static fn(): ResponseInterface => new TraceResponse(200));

        $span = $this->singleSpan($exporter);
        $attributes = $span->getAttributes();

        $this->assertSame('GET', $span->getName());
        $this->assertSame('GET', $attributes->get(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame('get', $attributes->get(HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL));
    }

    public function testUnexpectedMethodsUseOtherSemanticMethodAndHttpSpanNameToken(): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();

        $instrumentation->trace(TraceServerRequest::method('CUSTOM-TOKEN', '/method'), static fn(): ResponseInterface => new TraceResponse(200));

        $span = $this->singleSpan($exporter);
        $attributes = $span->getAttributes();

        $this->assertSame('HTTP', $span->getName());
        $this->assertSame(HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER, $attributes->get(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame('CUSTOM-TOKEN', $attributes->get(HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL));
    }

    public function testResponseTelemetryMutationFailureDoesNotReplaceSuccessfulResponse(): void
    {
        $response = new TraceResponse(201);
        $span = new CountingTraceSpan(new TraceScope());
        $span->throwOnAttributeKey = HttpAttributes::HTTP_RESPONSE_STATUS_CODE;
        $instrumentation = new HttpServerTraceInstrumentation(new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new TraceTracerProvider($span),
            resource: $this->resource(),
        ));

        $actual = $instrumentation->trace(TraceServerRequest::get('/telemetry-response-failure'), static fn(): ResponseInterface => $response);

        $this->assertSame($response, $actual);
        $this->assertSame(1, $span->endCalls);
    }

    public function testApplicationThrowableIsPreservedWhenErrorTelemetryMutationFails(): void
    {
        $throwable = new RuntimeException('application secret');
        $span = new CountingTraceSpan(new TraceScope());
        $span->throwOnAttributeKey = ErrorAttributes::ERROR_TYPE;
        $instrumentation = new HttpServerTraceInstrumentation(new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new TraceTracerProvider($span),
            resource: $this->resource(),
        ));

        try {
            $instrumentation->trace(TraceServerRequest::get('/telemetry-error-failure'), static function () use ($throwable): ResponseInterface {
                throw $throwable;
            });
            self::fail('Application throwable should be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($throwable, $caught);
        }

        $this->assertSame(1, $span->endCalls);
    }

    public function testSpanEndFailureStillDetachesAndDoesNotReplaceSuccessfulResponse(): void
    {
        $response = new TraceResponse(202);
        $scope = new TraceScope();
        $span = new CountingTraceSpan($scope);
        $span->throwOnEnd = true;
        $instrumentation = new HttpServerTraceInstrumentation(new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new TraceTracerProvider($span),
            resource: $this->resource(),
        ));

        $actual = $instrumentation->trace(TraceServerRequest::get('/end-failure'), static fn(): ResponseInterface => $response);

        $this->assertSame($response, $actual);
        $this->assertSame(1, $span->endCalls);
        $this->assertSame(1, $scope->detachCalls);
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testDetachFailureTakesPrecedenceOverApplicationThrowable(): void
    {
        $throwable = new RuntimeException('application failure');
        $span = new CountingTraceSpan(new TraceScope(ScopeInterface::MISMATCH));
        $instrumentation = new HttpServerTraceInstrumentation(new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new TraceTracerProvider($span),
            resource: $this->resource(),
        ));

        $this->expectException(OpenTelemetryContextDetachFailed::class);

        $instrumentation->trace(TraceServerRequest::get('/detach-precedence'), static function () use ($throwable): ResponseInterface {
            throw $throwable;
        });
    }

    public function testThrownDetachFailureTakesPrecedenceOverApplicationThrowable(): void
    {
        $throwable = new RuntimeException('application failure');
        $scope = new TraceScope();
        $scope->throwOnDetach = true;
        $span = new CountingTraceSpan($scope);
        $instrumentation = new HttpServerTraceInstrumentation(new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new TraceTracerProvider($span),
            resource: $this->resource(),
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('detach failed');

        $instrumentation->trace(TraceServerRequest::get('/detach-throws'), static function () use ($throwable): ResponseInterface {
            throw $throwable;
        });
    }

    public function testStateAttachmentFailureFallsBackWithoutLeakingActiveContext(): void
    {
        [$instrumentation, $exporter] = $this->instrumentationHarness();
        $request = TraceServerRequest::get('/state-attachment-failure')
            ->throwWhenAttachingAttribute(HttpServerSpanState::class);
        $response = new TraceResponse(200);
        $handledRequest = null;

        $actual = $instrumentation->trace(
            $request,
            static function (ServerRequestInterface $operationRequest) use (&$handledRequest, $response): ResponseInterface {
                $handledRequest = $operationRequest;

                return $response;
            },
        );

        $this->assertSame($response, $actual);
        $this->assertSame($request, $handledRequest);
        $this->assertFalse(Span::getCurrent()->getContext()->isValid());
        $this->assertCount(1, $exporter->getSpans());
    }

    /**
     * @return array{0: HttpServerTraceInstrumentation, 1: InMemoryExporter}
     */
    private function instrumentationHarness(): array
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProvider(new SimpleSpanProcessor($exporter), resource: $this->resource());

        return [
            new HttpServerTraceInstrumentation(new OpenTelemetryComposition(
                enabled: true,
                tracerProvider: $provider,
                resource: $this->resource(),
            )),
            $exporter,
        ];
    }

    private function resource(): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'observe-http-test']));
    }

    private function traceparent(): string
    {
        return '00-' . str_repeat('1', 32) . '-' . str_repeat('2', 16) . '-01';
    }

    private function singleSpan(InMemoryExporter $exporter): SpanDataInterface
    {
        $spans = $exporter->getSpans();

        $this->assertCount(1, $spans);

        return $spans[0];
    }
}

final class TraceServerRequest implements ServerRequestInterface
{
    /**
     * @param array<string, list<string>> $headers
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $queryParams
     * @param array<string, string> $cookieParams
     * @param array<mixed, mixed>|object|null $parsedBody
     */
    private function __construct(
        private readonly string $method,
        private readonly TraceUri $uri,
        private readonly array $headers = [],
        private readonly array $attributes = [],
        private readonly array $queryParams = [],
        private readonly array $cookieParams = [],
        private readonly array|object|null $parsedBody = null,
        private readonly ?string $throwOnAttributeName = null,
    ) {}

    public static function get(string $target): self
    {
        return self::method('GET', $target);
    }

    public static function method(string $method, string $target): self
    {
        return new self($method, TraceUri::fromTarget($target));
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
        return implode(',', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        $headers = $this->headers;
        $headers[strtolower($name)] = is_array($value) ? array_values($value) : [(string) $value];

        return new self($this->method, $this->uri, $headers, $this->attributes, $this->queryParams, $this->cookieParams, $this->parsedBody, $this->throwOnAttributeName);
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $headers = $this->headers;
        $key = strtolower($name);
        $headers[$key] = array_merge($headers[$key] ?? [], is_array($value) ? array_values($value) : [(string) $value]);

        return new self($this->method, $this->uri, $headers, $this->attributes, $this->queryParams, $this->cookieParams, $this->parsedBody, $this->throwOnAttributeName);
    }

    public function withoutHeader(string $name): MessageInterface
    {
        $headers = $this->headers;
        unset($headers[strtolower($name)]);

        return new self($this->method, $this->uri, $headers, $this->attributes, $this->queryParams, $this->cookieParams, $this->parsedBody, $this->throwOnAttributeName);
    }

    public function getBody(): StreamInterface
    {
        throw new BadMethodCallException('Request body must not be read by HTTP tracing.');
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        return $this;
    }

    public function getRequestTarget(): string
    {
        return (string) $this->uri;
    }

    public function withRequestTarget(string $requestTarget): ServerRequestInterface
    {
        return new self($this->method, TraceUri::fromTarget($requestTarget), $this->headers, $this->attributes, $this->queryParams, $this->cookieParams, $this->parsedBody, $this->throwOnAttributeName);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): ServerRequestInterface
    {
        return new self($method, $this->uri, $this->headers, $this->attributes, $this->queryParams, $this->cookieParams, $this->parsedBody, $this->throwOnAttributeName);
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): ServerRequestInterface
    {
        return new self($this->method, TraceUri::fromUri($uri), $this->headers, $this->attributes, $this->queryParams, $this->cookieParams, $this->parsedBody, $this->throwOnAttributeName);
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
        return $this->cookieParams;
    }

    /**
     * @param array<string, string> $cookies
     */
    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        return new self($this->method, $this->uri, $this->headers, $this->attributes, $this->queryParams, $cookies, $this->parsedBody, $this->throwOnAttributeName);
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
        return new self($this->method, $this->uri, $this->headers, $this->attributes, $query, $this->cookieParams, $this->parsedBody, $this->throwOnAttributeName);
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
     * @return array<mixed, mixed>|object|null
     */
    public function getParsedBody()
    {
        return $this->parsedBody;
    }

    /**
     * @param array<mixed, mixed>|object|null $data
     */
    public function withParsedBody($data): ServerRequestInterface
    {
        return new self($this->method, $this->uri, $this->headers, $this->attributes, $this->queryParams, $this->cookieParams, $data, $this->throwOnAttributeName);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        if ($name === $this->throwOnAttributeName) {
            throw new RuntimeException('attribute attachment failed');
        }

        $attributes = $this->attributes;
        $attributes[$name] = $value;

        return new self($this->method, $this->uri, $this->headers, $attributes, $this->queryParams, $this->cookieParams, $this->parsedBody, $this->throwOnAttributeName);
    }

    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $attributes = $this->attributes;
        unset($attributes[$name]);

        return new self($this->method, $this->uri, $this->headers, $attributes, $this->queryParams, $this->cookieParams, $this->parsedBody, $this->throwOnAttributeName);
    }

    public function throwWhenAttachingAttribute(string $name): self
    {
        return new self($this->method, $this->uri, $this->headers, $this->attributes, $this->queryParams, $this->cookieParams, $this->parsedBody, $name);
    }
}

final readonly class TraceUri implements UriInterface
{
    private function __construct(private string $path, private string $query = '', private string $scheme = 'https') {}

    public static function fromTarget(string $target): self
    {
        $parts = explode('?', $target, 2);

        return new self($parts[0], $parts[1] ?? '');
    }

    public static function fromUri(UriInterface $uri): self
    {
        return new self($uri->getPath(), $uri->getQuery(), $uri->getScheme());
    }

    public function getScheme(): string
    {
        return $this->scheme;
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
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return '';
    }

    public function withScheme(string $scheme): UriInterface
    {
        return new self($this->path, $this->query, $scheme);
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
        return new self($path, $this->query, $this->scheme);
    }

    public function withQuery(string $query): UriInterface
    {
        return new self($this->path, $query, $this->scheme);
    }

    public function withFragment(string $fragment): UriInterface
    {
        return $this;
    }

    public function __toString(): string
    {
        return $this->query === '' ? $this->path : $this->path . '?' . $this->query;
    }
}

final readonly class TraceResponse implements ResponseInterface
{
    public function __construct(private int $statusCode) {}

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
        throw new BadMethodCallException('Response body must not be read by HTTP tracing.');
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
        return new self($code);
    }

    public function getReasonPhrase(): string
    {
        return '';
    }
}

final class TraceTracerProvider implements TracerProviderInterface
{
    public function __construct(private CountingTraceSpan $span) {}

    /**
     * @param iterable<mixed> $attributes
     */
    public function getTracer(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): TracerInterface
    {
        return new TraceTracer($this->span);
    }
}

final class TraceTracer implements TracerInterface
{
    public function __construct(private CountingTraceSpan $span) {}

    public function spanBuilder(string $spanName): SpanBuilderInterface
    {
        return new TraceSpanBuilder($this->span);
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

final class TraceSpanBuilder implements SpanBuilderInterface
{
    public function __construct(private CountingTraceSpan $span) {}

    public function setParent(ContextInterface|false|null $context): SpanBuilderInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanBuilderInterface
    {
        return $this;
    }

    public function setAttribute(string $key, mixed $value): SpanBuilderInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function setAttributes(iterable $attributes): SpanBuilderInterface
    {
        return $this;
    }

    public function setStartTimestamp(int $timestampNanos): SpanBuilderInterface
    {
        return $this;
    }

    public function setSpanKind(int $spanKind): SpanBuilderInterface
    {
        return $this;
    }

    public function startSpan(): SpanInterface
    {
        return $this->span;
    }
}

final class CountingTraceSpan implements SpanInterface
{
    public int $endCalls = 0;

    public ?string $throwOnAttributeKey = null;

    public bool $throwOnStatus = false;

    public bool $throwOnEnd = false;

    public function __construct(private ScopeInterface $scope) {}

    public static function fromContext(ContextInterface $context): SpanInterface
    {
        return Span::fromContext($context);
    }

    public static function getCurrent(): SpanInterface
    {
        return Span::getCurrent();
    }

    public static function getInvalid(): SpanInterface
    {
        return Span::getInvalid();
    }

    public static function wrap(SpanContextInterface $spanContext): SpanInterface
    {
        return Span::wrap($spanContext);
    }

    public function activate(): ScopeInterface
    {
        return $this->scope;
    }

    public function storeInContext(ContextInterface $context): ContextInterface
    {
        return $context;
    }

    public function getContext(): SpanContextInterface
    {
        return SpanContext::create(str_repeat('1', 32), str_repeat('2', 16), TraceFlags::SAMPLED);
    }

    public function isRecording(): bool
    {
        return true;
    }

    /**
     * @param bool|int|float|string|array<array-key, mixed>|null $value
     */
    public function setAttribute(string $key, bool|int|float|string|array|null $value): SpanInterface
    {
        if ($key === $this->throwOnAttributeKey) {
            throw new RuntimeException('telemetry attribute failed');
        }

        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function setAttributes(iterable $attributes): SpanInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function addEvent(string $name, iterable $attributes = [], ?int $timestamp = null): SpanInterface
    {
        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function recordException(\Throwable $exception, iterable $attributes = []): SpanInterface
    {
        return $this;
    }

    public function updateName(string $name): SpanInterface
    {
        return $this;
    }

    public function setStatus(string $code, ?string $description = null): SpanInterface
    {
        if ($this->throwOnStatus) {
            throw new RuntimeException('telemetry status failed');
        }

        return $this;
    }

    public function end(?int $endEpochNanos = null): void
    {
        ++$this->endCalls;

        if ($this->throwOnEnd) {
            throw new RuntimeException('span end failed');
        }
    }
}

final class TraceScope implements ScopeInterface
{
    public int $detachCalls = 0;

    public bool $throwOnDetach = false;

    public function __construct(private int $detachStatus = 0) {}

    public function detach(): int
    {
        ++$this->detachCalls;

        if ($this->throwOnDetach) {
            throw new RuntimeException('detach failed');
        }

        return $this->detachStatus;
    }
}
