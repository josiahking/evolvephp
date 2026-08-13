<?php

declare(strict_types=1);

namespace Evolve\Http\Tests\Unit\Routing;

use BadMethodCallException;
use Evolve\Http\Exception\MethodNotAllowed;
use Evolve\Http\Exception\RouteNotFound;
use Evolve\Http\Middleware\MiddlewarePipeline;
use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteCollection;
use Evolve\Http\Routing\RouteMatch;
use Evolve\Http\Routing\RouteMatcher;
use Evolve\Http\Routing\RoutingRequestHandler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

final class RoutedHandlerDispatchTest extends TestCase
{
    public function test_routing_request_handler_is_a_final_readonly_psr_15_request_handler(): void
    {
        $handler = new RoutingRequestHandler($this->matcher([]));
        $reflection = new \ReflectionClass(RoutingRequestHandler::class);

        self::assertContains(RequestHandlerInterface::class, class_implements($handler));
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }

    public function test_constructor_rejects_invalid_post_match_middleware_immediately(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RoutingRequestHandler($this->matcher([]), [new RecordingPostMatchMiddleware('valid'), new \stdClass()]);
    }

    public function test_constructor_consumes_post_match_middleware_iterable_once_and_preserves_order(): void
    {
        $constructionLog = new DispatchLog();
        $executionLog = new DispatchLog();
        $response = $this->response();
        $routeHandler = new RoutedDispatchRecordingRouteHandler($response, $executionLog);
        $middleware = function () use ($constructionLog, $executionLog): iterable {
            $constructionLog->add('yield A');
            yield new RecordingPostMatchMiddleware('A', $executionLog);

            $constructionLog->add('yield B');
            yield new RecordingPostMatchMiddleware('B', $executionLog);
        };

        $routingHandler = new RoutingRequestHandler(
            $this->matcher([new Route(['GET'], '/users/{id}', $routeHandler)]),
            $middleware(),
        );

        self::assertSame(['yield A', 'yield B'], $constructionLog->all());

        $constructionLog->add('after construction');

        self::assertSame($response, $routingHandler->handle($this->request('GET', '/users/42')));
        self::assertSame(['yield A', 'yield B', 'after construction'], $constructionLog->all());
        self::assertSame(['A before', 'B before', 'route handler', 'B after', 'A after'], $executionLog->all());
    }

    public function test_successful_match_invokes_exact_route_handler_and_returns_exact_response(): void
    {
        $firstResponse = $this->response();
        $secondResponse = $this->response();
        $firstHandler = new RoutedDispatchRecordingRouteHandler($firstResponse);
        $secondHandler = new RoutedDispatchRecordingRouteHandler($secondResponse);
        $routingHandler = new RoutingRequestHandler($this->matcher([
            new Route(['POST'], '/users/{id}', $firstHandler),
            new Route(['GET'], '/users/{id}', $secondHandler),
        ]));

        $result = $routingHandler->handle($this->request('GET', '/users/42'));

        self::assertSame($secondResponse, $result);
        self::assertSame([], $firstHandler->requests);
        self::assertCount(1, $secondHandler->requests);
    }

    public function test_successful_match_attaches_authoritative_route_match_attribute_only(): void
    {
        $response = $this->response();
        $routeHandler = new RoutedDispatchRecordingRouteHandler($response);
        $route = new Route(['GET'], '/users/{id}', $routeHandler);
        $staleRoute = new Route(['GET'], '/stale/{id}', new RoutedDispatchRecordingRouteHandler($this->response()));
        $staleMatch = new RouteMatch($staleRoute, ['id' => 'stale']);
        $request = $this->request('GET', '/users/42')
            ->withAttribute(RouteMatch::class, $staleMatch)
            ->withAttribute('id', 'stale-top-level');

        self::assertSame($response, (new RoutingRequestHandler($this->matcher([$route])))->handle($request));

        $routedRequest = $routeHandler->requests[0];
        $match = $routedRequest->getAttribute(RouteMatch::class);

        self::assertInstanceOf(RouteMatch::class, $match);
        self::assertNotSame($staleMatch, $match);
        self::assertSame($route, $match->route());
        self::assertSame(['id' => '42'], $match->parameters());
        self::assertSame('stale-top-level', $routedRequest->getAttribute('id'));
        self::assertNotSame('42', $routedRequest->getAttribute('id'));
    }

    public function test_post_match_middleware_sees_authoritative_route_match_before_route_handler(): void
    {
        $log = new DispatchLog();
        $response = $this->response();
        $routeHandler = new RoutedDispatchRecordingRouteHandler($response, $log);
        $inspector = new RouteMatchRecordingMiddleware('A', $log);
        $route = new Route(['GET'], '/users/{id}', $routeHandler);

        self::assertSame(
            $response,
            (new RoutingRequestHandler($this->matcher([$route]), [$inspector]))->handle($this->request('GET', '/users/42')),
        );

        self::assertSame(['A before', 'route handler', 'A after'], $log->all());
        self::assertCount(1, $inspector->matches);
        self::assertSame($route, $inspector->matches[0]->route());
        self::assertSame($inspector->matches[0], $routeHandler->requests[0]->getAttribute(RouteMatch::class));
    }

    public function test_post_match_middleware_runs_in_onion_order(): void
    {
        $log = new DispatchLog();
        $response = $this->response();

        $result = (new RoutingRequestHandler(
            $this->matcher([new Route(['GET'], '/users', new RoutedDispatchRecordingRouteHandler($response, $log))]),
            [
                new RecordingPostMatchMiddleware('A', $log),
                new RecordingPostMatchMiddleware('B', $log),
            ],
        ))->handle($this->request('GET', '/users'));

        self::assertSame($response, $result);
        self::assertSame(['A before', 'B before', 'route handler', 'B after', 'A after'], $log->all());
    }

    public function test_post_match_short_circuit_returns_exact_response_without_later_middleware_or_route_handler(): void
    {
        $log = new DispatchLog();
        $shortCircuitResponse = $this->response();
        $routeHandler = new RoutedDispatchRecordingRouteHandler($this->response(), $log);

        $result = (new RoutingRequestHandler(
            $this->matcher([new Route(['GET'], '/users', $routeHandler)]),
            [
                new ShortCircuitPostMatchMiddleware('A', $shortCircuitResponse, $log),
                new RecordingPostMatchMiddleware('B', $log),
            ],
        ))->handle($this->request('GET', '/users'));

        self::assertSame($shortCircuitResponse, $result);
        self::assertSame(['A before'], $log->all());
        self::assertSame([], $routeHandler->requests);
    }

    public function test_post_match_replacement_request_flows_downstream(): void
    {
        $originalRequest = $this->request('GET', '/users')->withAttribute('name', 'original');
        $replacementRequest = $this->request('GET', '/users')->withAttribute('name', 'replacement');
        $response = $this->response();
        $routeHandler = new RoutedDispatchRecordingRouteHandler($response);
        $downstream = new RequestRecordingMiddleware();

        self::assertSame(
            $response,
            (new RoutingRequestHandler(
                $this->matcher([new Route(['GET'], '/users', $routeHandler)]),
                [
                    new ReplacementRequestMiddleware($replacementRequest),
                    $downstream,
                ],
            ))->handle($originalRequest),
        );

        self::assertSame([$replacementRequest], $downstream->requests);
        self::assertSame([$replacementRequest], $routeHandler->requests);
    }

    public function test_post_match_middleware_throwable_propagates_unchanged(): void
    {
        $throwable = new RuntimeException('middleware failed');
        $routeHandler = new RoutedDispatchRecordingRouteHandler($this->response());

        try {
            (new RoutingRequestHandler(
                $this->matcher([new Route(['GET'], '/users', $routeHandler)]),
                [new ThrowingPostMatchMiddleware($throwable)],
            ))->handle($this->request('GET', '/users'));
            self::fail('Expected middleware throwable to propagate.');
        } catch (Throwable $caught) {
            self::assertSame($throwable, $caught);
            self::assertSame([], $routeHandler->requests);
        }
    }

    public function test_route_handler_throwable_propagates_unchanged(): void
    {
        $throwable = new RuntimeException('handler failed');

        try {
            (new RoutingRequestHandler($this->matcher([
                new Route(['GET'], '/users', new ThrowingRouteHandler($throwable)),
            ])))->handle($this->request('GET', '/users'));
            self::fail('Expected route handler throwable to propagate.');
        } catch (Throwable $caught) {
            self::assertSame($throwable, $caught);
        }
    }

    public function test_unknown_path_throws_route_not_found_without_executing_post_match_chain(): void
    {
        $log = new DispatchLog();
        $routeHandler = new RoutedDispatchRecordingRouteHandler($this->response(), $log);

        try {
            (new RoutingRequestHandler(
                $this->matcher([new Route(['GET'], '/users/{id}', $routeHandler)]),
                [new RecordingPostMatchMiddleware('A', $log)],
            ))->handle($this->request('GET', '/secret/acme'));
            self::fail('Expected RouteNotFound to be thrown.');
        } catch (RouteNotFound $exception) {
            self::assertSame([], $log->all());
            self::assertStringNotContainsString('/secret/acme', $exception->getMessage());
            self::assertStringNotContainsString('acme', $exception->getMessage());
        }
    }

    public function test_wrong_method_throws_method_not_allowed_with_exact_allowed_methods_without_executing_post_match_chain(): void
    {
        $log = new DispatchLog();
        $routeHandler = new RoutedDispatchRecordingRouteHandler($this->response(), $log);

        try {
            (new RoutingRequestHandler(
                $this->matcher([
                    new Route(['POST', 'get'], '/users/{id}', $routeHandler),
                    new Route(['PATCH'], '/users/42', $routeHandler),
                ]),
                [new RecordingPostMatchMiddleware('A', $log)],
            ))->handle($this->request('GET', '/users/42'));
            self::fail('Expected MethodNotAllowed to be thrown.');
        } catch (MethodNotAllowed $exception) {
            self::assertSame(['POST', 'get', 'PATCH'], $exception->allowedMethods());
            self::assertSame([], $log->all());
            self::assertStringNotContainsString('/users/42', $exception->getMessage());
            self::assertStringNotContainsString('42', $exception->getMessage());
        }
    }

    public function test_head_and_options_are_not_implicitly_allowed(): void
    {
        $routingHandler = new RoutingRequestHandler($this->matcher([
            new Route(['GET'], '/users', new RoutedDispatchRecordingRouteHandler($this->response())),
        ]));

        try {
            $routingHandler->handle($this->request('HEAD', '/users'));
            self::fail('Expected HEAD to be method-not-allowed.');
        } catch (MethodNotAllowed $exception) {
            self::assertSame(['GET'], $exception->allowedMethods());
        }

        try {
            $routingHandler->handle($this->request('OPTIONS', '/users'));
            self::fail('Expected OPTIONS to be method-not-allowed.');
        } catch (MethodNotAllowed $exception) {
            self::assertSame(['GET'], $exception->allowedMethods());
        }
    }

    public function test_later_method_compatible_route_dispatches_after_earlier_method_mismatch(): void
    {
        $postHandler = new RoutedDispatchRecordingRouteHandler($this->response());
        $getResponse = $this->response();
        $getHandler = new RoutedDispatchRecordingRouteHandler($getResponse);

        $result = (new RoutingRequestHandler($this->matcher([
            new Route(['POST'], '/users/{id}', $postHandler),
            new Route(['GET'], '/users/{id}', $getHandler),
        ])))->handle($this->request('GET', '/users/42'));

        self::assertSame($getResponse, $result);
        self::assertSame([], $postHandler->requests);
        self::assertSame(['id' => '42'], $getHandler->requests[0]->getAttribute(RouteMatch::class)->parameters());
    }

    public function test_outer_middleware_pipeline_composes_before_routing_and_post_match_middleware(): void
    {
        $log = new DispatchLog();
        $response = $this->response();
        $routingHandler = new RoutingRequestHandler(
            $this->matcher([new Route(['GET'], '/users', new RoutedDispatchRecordingRouteHandler($response, $log))]),
            [new RecordingPostMatchMiddleware('post', $log)],
        );
        $pipeline = new MiddlewarePipeline([new RecordingPostMatchMiddleware('pre', $log)], $routingHandler);

        self::assertSame($response, $pipeline->handle($this->request('GET', '/users')));
        self::assertSame(['pre before', 'post before', 'route handler', 'post after', 'pre after'], $log->all());
    }

    public function test_outer_pre_routing_middleware_may_short_circuit_before_route_matching(): void
    {
        $log = new DispatchLog();
        $shortCircuitResponse = $this->response();
        $routeHandler = new RoutedDispatchRecordingRouteHandler($this->response(), $log);
        $routingHandler = new RoutingRequestHandler(
            $this->matcher([new Route(['GET'], '/users', $routeHandler)]),
            [new RecordingPostMatchMiddleware('post', $log)],
        );
        $pipeline = new MiddlewarePipeline([new ShortCircuitPostMatchMiddleware('pre', $shortCircuitResponse, $log)], $routingHandler);

        self::assertSame($shortCircuitResponse, $pipeline->handle($this->request('GET', '/users')));
        self::assertSame(['pre before'], $log->all());
        self::assertSame([], $routeHandler->requests);
    }

    public function test_sequential_reuse_rematches_each_request_without_leaking_route_match_state(): void
    {
        $response = $this->response();
        $usersHandler = new RoutedDispatchRecordingRouteHandler($response);
        $projectsHandler = new RoutedDispatchRecordingRouteHandler($response);
        $routingHandler = new RoutingRequestHandler($this->matcher([
            new Route(['GET'], '/users/{id}', $usersHandler),
            new Route(['GET'], '/projects/{project}', $projectsHandler),
        ]));

        self::assertSame($response, $routingHandler->handle($this->request('GET', '/users/42')));

        try {
            $routingHandler->handle($this->request('GET', '/missing/acme'));
            self::fail('Expected failed routing between successful requests.');
        } catch (RouteNotFound) {
            self::assertSame(1, $usersHandler->requestCount());
            self::assertSame(0, $projectsHandler->requestCount());
        }

        self::assertSame($response, $routingHandler->handle($this->request('GET', '/projects/alpha')));

        self::assertSame(1, $usersHandler->requestCount());
        self::assertSame(1, $projectsHandler->requestCount());
        self::assertSame(['id' => '42'], $usersHandler->firstRequest()->getAttribute(RouteMatch::class)->parameters());
        self::assertSame(['project' => 'alpha'], $projectsHandler->firstRequest()->getAttribute(RouteMatch::class)->parameters());
    }

    public function test_method_not_allowed_is_final_runtime_exception_and_preserves_valid_methods(): void
    {
        $exception = new MethodNotAllowed(['POST', 'get', 'PATCH']);
        $reflection = new \ReflectionClass(MethodNotAllowed::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isSubclassOf(RuntimeException::class));
        self::assertSame(['POST', 'get', 'PATCH'], $exception->allowedMethods());
    }

    public function test_method_not_allowed_rejects_invalid_method_lists(): void
    {
        foreach (
            [
                [],
                [1 => 'GET'],
                [''],
                ['GET', 123],
                ['GET', 'GET'],
            ] as $allowedMethods
        ) {
            try {
                new MethodNotAllowed($allowedMethods);
                self::fail('Expected invalid allowed-method list to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_route_not_found_is_final_runtime_exception(): void
    {
        $exception = new RouteNotFound();
        $reflection = new \ReflectionClass(RouteNotFound::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isSubclassOf(RuntimeException::class));
    }

    /**
     * @param iterable<Route> $routes
     */
    private function matcher(iterable $routes): RouteMatcher
    {
        return new RouteMatcher(new RouteCollection($routes));
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return new TestServerRequest($method, new TestUri($path));
    }

    private function response(): ResponseInterface
    {
        return $this->createStub(ResponseInterface::class);
    }
}

final class RoutedDispatchRecordingRouteHandler implements RequestHandlerInterface
{
    /**
     * @var list<ServerRequestInterface>
     */
    public array $requests = [];

    public function __construct(
        private readonly ResponseInterface $response,
        ?DispatchLog $log = null,
    ) {
        $this->log = $log ?? new DispatchLog();
    }

    private DispatchLog $log;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->log->add('route handler');

        return $this->response;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    public function firstRequest(): ServerRequestInterface
    {
        if (!isset($this->requests[0])) {
            throw new RuntimeException('No request was recorded.');
        }

        return $this->requests[0];
    }
}

final class ThrowingRouteHandler implements RequestHandlerInterface
{
    public function __construct(private readonly Throwable $throwable) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw $this->throwable;
    }
}

final class RecordingPostMatchMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
        ?DispatchLog $log = null,
    ) {
        $this->log = $log ?? new DispatchLog();
    }

    private DispatchLog $log;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->log->add($this->name . ' before');
        $response = $handler->handle($request);
        $this->log->add($this->name . ' after');

        return $response;
    }
}

final class RouteMatchRecordingMiddleware implements MiddlewareInterface
{
    /**
     * @var list<RouteMatch>
     */
    public array $matches = [];

    public function __construct(
        private readonly string $name,
        private readonly DispatchLog $log,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->log->add($this->name . ' before');
        $match = $request->getAttribute(RouteMatch::class);

        if (!$match instanceof RouteMatch) {
            throw new RuntimeException('RouteMatch attribute was not attached before post-match middleware.');
        }

        $this->matches[] = $match;
        $response = $handler->handle($request);
        $this->log->add($this->name . ' after');

        return $response;
    }
}

final class ShortCircuitPostMatchMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
        private readonly ResponseInterface $response,
        private readonly DispatchLog $log,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->log->add($this->name . ' before');

        return $this->response;
    }
}

final class ReplacementRequestMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ServerRequestInterface $replacementRequest) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($this->replacementRequest);
    }
}

final class RequestRecordingMiddleware implements MiddlewareInterface
{
    /**
     * @var list<ServerRequestInterface>
     */
    public array $requests = [];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->requests[] = $request;

        return $handler->handle($request);
    }
}

final class ThrowingPostMatchMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Throwable $throwable) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        throw $this->throwable;
    }
}

final class DispatchLog
{
    /**
     * @var list<string>
     */
    private array $events = [];

    public function add(string $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->events;
    }
}

final readonly class TestUri implements UriInterface
{
    public function __construct(private string $path) {}

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
        return $this->path;
    }

    public function getQuery(): string
    {
        return '';
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
        return new self($path);
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
        return $this->path;
    }
}

final readonly class TestServerRequest implements ServerRequestInterface
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private string $method,
        private UriInterface $uri,
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
        throw new BadMethodCallException('Test request body is not implemented.');
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        return $this;
    }

    public function getRequestTarget(): string
    {
        return $this->uri->getPath();
    }

    public function withRequestTarget(string $requestTarget): ServerRequestInterface
    {
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): ServerRequestInterface
    {
        return new self($method, $this->uri, $this->attributes);
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): ServerRequestInterface
    {
        return new self($this->method, $uri, $this->attributes);
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
     * @return array<mixed, mixed>|object|null
     */
    public function getParsedBody()
    {
        return null;
    }

    /**
     * @param array<mixed, mixed>|object|null $data
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

    public function getAttribute(string $name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $attributes = $this->attributes;
        $attributes[$name] = $value;

        return new self($this->method, $this->uri, $attributes);
    }

    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $attributes = $this->attributes;
        unset($attributes[$name]);

        return new self($this->method, $this->uri, $attributes);
    }
}
