<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Http;

use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteMatch;
use Evolve\Observe\Http\HttpRouteSpanMiddleware;
use Evolve\Observe\Http\Internal\HttpServerSpanState;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HttpRouteSpanMiddlewareTest extends TestCase
{
    public function testMissingServerSpanStateBehavesTransparently(): void
    {
        $middleware = new HttpRouteSpanMiddleware();
        $request = TraceServerRequest::get('/users/123');
        $response = new TraceResponse(200);
        $handler = new RouteMiddlewareHandler($response);

        $actual = $middleware->process($request, $handler);

        $this->assertSame($response, $actual);
        $this->assertSame($request, $handler->request);
    }

    public function testMatchedRouteTemplateUpdatesExistingServerSpanWithoutConcretePath(): void
    {
        $span = new RecordingRouteSpan();
        $middleware = new HttpRouteSpanMiddleware();
        $route = new Route(['GET'], '/users/{id}', new RouteMiddlewareHandler(new TraceResponse(200)));
        $request = TraceServerRequest::get('/users/123')
            ->withAttribute(HttpServerSpanState::class, new HttpServerSpanState($span, 'GET'))
            ->withAttribute(RouteMatch::class, new RouteMatch($route, ['id' => '123']));

        $middleware->process($request, new RouteMiddlewareHandler(new TraceResponse(200)));

        $this->assertSame('/users/{id}', $span->attributes[HttpAttributes::HTTP_ROUTE]);
        $this->assertSame('GET /users/{id}', $span->name);
        $this->assertStringNotContainsString('/users/123', json_encode($span->attributes, JSON_THROW_ON_ERROR));
        $this->assertSame(0, $span->endCalls);
    }

    public function testUnknownMethodRouteTemplateUsesHttpSpanNameToken(): void
    {
        $span = new RecordingRouteSpan();
        $middleware = new HttpRouteSpanMiddleware();
        $route = new Route(['CUSTOM-TOKEN'], '/custom/{id}', new RouteMiddlewareHandler(new TraceResponse(200)));
        $request = TraceServerRequest::method('CUSTOM-TOKEN', '/custom/123')
            ->withAttribute(HttpServerSpanState::class, new HttpServerSpanState($span, HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER))
            ->withAttribute(RouteMatch::class, new RouteMatch($route, ['id' => '123']));

        $middleware->process($request, new RouteMiddlewareHandler(new TraceResponse(200)));

        $this->assertSame('/custom/{id}', $span->attributes[HttpAttributes::HTTP_ROUTE]);
        $this->assertSame('HTTP /custom/{id}', $span->name);
    }

    public function testRouteTelemetryFailureDoesNotFailDownstreamHandler(): void
    {
        $span = new RecordingRouteSpan();
        $span->throwOnAttributeKey = HttpAttributes::HTTP_ROUTE;
        $middleware = new HttpRouteSpanMiddleware();
        $route = new Route(['GET'], '/users/{id}', new RouteMiddlewareHandler(new TraceResponse(200)));
        $request = TraceServerRequest::get('/users/123')
            ->withAttribute(HttpServerSpanState::class, new HttpServerSpanState($span, 'GET'))
            ->withAttribute(RouteMatch::class, new RouteMatch($route, ['id' => '123']));
        $response = new TraceResponse(204);
        $handler = new RouteMiddlewareHandler($response);

        $actual = $middleware->process($request, $handler);

        $this->assertSame($response, $actual);
        $this->assertSame($request, $handler->request);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function missingRouteStatusProvider(): iterable
    {
        yield 'not found' => [404];
        yield 'method not allowed' => [405];
    }

    #[DataProvider('missingRouteStatusProvider')]
    public function testMissingRouteMatchLeavesServerSpanMethodOnlyForUnmatchedResponses(int $statusCode): void
    {
        $span = new RecordingRouteSpan();
        $middleware = new HttpRouteSpanMiddleware();
        $request = TraceServerRequest::get('/missing')
            ->withAttribute(HttpServerSpanState::class, new HttpServerSpanState($span, 'GET'));

        $middleware->process($request, new RouteMiddlewareHandler(new TraceResponse($statusCode)));

        $this->assertSame([], $span->attributes);
        $this->assertNull($span->name);
    }
}

final class RouteMiddlewareHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $request = null;

    public function __construct(private ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return $this->response;
    }
}

final class RecordingRouteSpan implements SpanInterface
{
    /**
     * @var array<string, bool|int|float|string|array<array-key, mixed>|null>
     */
    public array $attributes = [];

    public ?string $name = null;

    public int $endCalls = 0;

    public ?string $throwOnAttributeKey = null;

    public bool $throwOnNameUpdate = false;

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
        return new TraceScope();
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
            throw new \RuntimeException('route telemetry attribute failed');
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * @param iterable<mixed> $attributes
     */
    public function setAttributes(iterable $attributes): SpanInterface
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }

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
        if ($this->throwOnNameUpdate) {
            throw new \RuntimeException('route telemetry name failed');
        }

        $this->name = $name;

        return $this;
    }

    public function setStatus(string $code, ?string $description = null): SpanInterface
    {
        return $this;
    }

    public function end(?int $endEpochNanos = null): void
    {
        ++$this->endCalls;
    }
}
