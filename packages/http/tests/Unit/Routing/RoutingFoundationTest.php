<?php

declare(strict_types=1);

namespace Evolve\Http\Tests\Unit\Routing;

use Evolve\Http\Routing\Internal\RoutePattern;
use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteCollection;
use Evolve\Http\Routing\RouteMatch;
use Evolve\Http\Routing\RouteMatcher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RoutingFoundationTest extends TestCase
{
    public function test_route_preserves_exact_ordered_method_list(): void
    {
        $route = new Route(['GET', 'post'], '/users', $this->handler());

        self::assertSame(['GET', 'post'], $route->methods());
    }

    public function test_route_preserves_exact_path_template(): void
    {
        $route = new Route(['GET'], '/users/{id}', $this->handler());

        self::assertSame('/users/{id}', $route->path());
    }

    public function test_route_preserves_exact_handler_instance(): void
    {
        $handler = $this->handler();
        $route = new Route(['GET'], '/users', $handler);

        self::assertSame($handler, $route->handler());
    }

    public function test_route_definition_objects_are_final_and_readonly(): void
    {
        self::assertTrue((new \ReflectionClass(Route::class))->isFinal());
        self::assertTrue((new \ReflectionClass(Route::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(RouteCollection::class))->isFinal());
        self::assertTrue((new \ReflectionClass(RouteCollection::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(RouteMatch::class))->isFinal());
        self::assertTrue((new \ReflectionClass(RouteMatch::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(RouteMatcher::class))->isFinal());
        self::assertTrue((new \ReflectionClass(RouteMatcher::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(RoutePattern::class))->isFinal());
        self::assertTrue((new \ReflectionClass(RoutePattern::class))->isReadOnly());
    }

    public function test_empty_method_iterable_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Route([], '/users', $this->handler());
    }

    public function test_non_string_method_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Route(['GET', 123], '/users', $this->handler());
    }

    public function test_empty_method_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Route([''], '/users', $this->handler());
    }

    public function test_whitespace_control_and_invalid_method_tokens_are_rejected(): void
    {
        foreach (['POST GET', "GET\n", 'GE<T', 'GE:T'] as $method) {
            $this->assertInvalidRouteMethod($method);
        }
    }

    public function test_duplicate_methods_inside_one_route_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Route(['GET', 'POST', 'GET'], '/users', $this->handler());
    }

    public function test_method_case_remains_exact(): void
    {
        $route = new Route(['GET', 'get'], '/users', $this->handler());

        self::assertSame(['GET', 'get'], $route->methods());
    }

    public function test_path_must_begin_with_slash(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Route(['GET'], 'users', $this->handler());
    }

    public function test_root_path_is_accepted(): void
    {
        $route = new Route(['GET'], '/', $this->handler());

        self::assertSame('/', $route->path());
    }

    public function test_query_fragment_and_control_characters_are_rejected_in_route_templates(): void
    {
        foreach (['/users?active=1', '/users#profile', "/users\n"] as $path) {
            $this->assertInvalidRoutePath($path);
        }
    }

    public function test_valid_whole_segment_parameters_are_accepted(): void
    {
        $route = new Route(['GET'], '/accounts/{account}/transactions/{transaction_1}', $this->handler());

        self::assertSame('/accounts/{account}/transactions/{transaction_1}', $route->path());
    }

    public function test_invalid_parameter_names_are_rejected(): void
    {
        foreach (['/users/{}', '/users/{123}', '/users/{first-name}'] as $path) {
            $this->assertInvalidRoutePath($path);
        }
    }

    public function test_duplicate_parameter_names_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Route(['GET'], '/users/{id}/{id}', $this->handler());
    }

    public function test_partial_segment_braces_are_rejected(): void
    {
        foreach (['/users/prefix-{id}', '/users/{id}.json', '/users/id}'] as $path) {
            $this->assertInvalidRoutePath($path);
        }
    }

    public function test_optional_wildcard_and_constraint_syntax_are_rejected(): void
    {
        foreach (['/users/{id:\d+}', '/users/{id?}', '/users/{*path}'] as $path) {
            $this->assertInvalidRoutePath($path);
        }
    }

    public function test_empty_route_collection_is_accepted(): void
    {
        self::assertSame([], (new RouteCollection([]))->all());
    }

    public function test_route_collection_preserves_insertion_order(): void
    {
        $first = $this->route(['GET'], '/first');
        $second = $this->route(['POST'], '/second');

        self::assertSame([$first, $second], (new RouteCollection([$first, $second]))->all());
    }

    public function test_route_collection_consumes_traversable_once(): void
    {
        $first = $this->route(['GET'], '/first');
        $second = $this->route(['POST'], '/second');

        $routes = function () use ($first, $second): iterable {
            yield $first;
            yield $second;
        };

        self::assertSame([$first, $second], (new RouteCollection($routes()))->all());
    }

    public function test_route_collection_rejects_invalid_entries_immediately(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RouteCollection([$this->route(['GET'], '/valid'), new \stdClass()]);
    }

    public function test_route_match_preserves_exact_route_and_parameters(): void
    {
        $route = $this->route(['GET'], '/users/{id}');
        $match = new RouteMatch($route, ['id' => '42']);

        self::assertSame($route, $match->route());
        self::assertSame(['id' => '42'], $match->parameters());
    }

    public function test_route_match_rejects_non_string_parameter_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RouteMatch($this->route(['GET'], '/users/{id}'), ['id' => 42]);
    }

    public function test_static_route_matches(): void
    {
        $route = $this->route(['GET'], '/users');

        self::assertSame($route, $this->matcher([$route])->match($this->request('GET', '/users'))?->route());
    }

    public function test_root_route_matches(): void
    {
        $route = $this->route(['GET'], '/');

        self::assertSame($route, $this->matcher([$route])->match($this->request('GET', '/'))?->route());
    }

    public function test_static_path_comparison_is_case_sensitive(): void
    {
        $route = $this->route(['GET'], '/Users');

        self::assertNull($this->matcher([$route])->match($this->request('GET', '/users')));
    }

    public function test_trailing_slash_remains_significant(): void
    {
        $route = $this->route(['GET'], '/users');

        self::assertNull($this->matcher([$route])->match($this->request('GET', '/users/')));
    }

    public function test_one_parameter_is_captured(): void
    {
        $route = $this->route(['GET'], '/users/{id}');
        $match = $this->matcher([$route])->match($this->request('GET', '/users/42'));

        self::assertSame(['id' => '42'], $match?->parameters());
    }

    public function test_multiple_parameters_are_captured_by_name(): void
    {
        $route = $this->route(['GET'], '/accounts/{account}/transactions/{transaction}');
        $match = $this->matcher([$route])->match($this->request('GET', '/accounts/acme/transactions/tx-9'));

        self::assertSame(['account' => 'acme', 'transaction' => 'tx-9'], $match?->parameters());
    }

    public function test_parameter_cannot_match_empty_segment(): void
    {
        $route = $this->route(['GET'], '/users/{id}');

        self::assertNull($this->matcher([$route])->match($this->request('GET', '/users/')));
    }

    public function test_captured_percent_encoded_value_remains_encoded(): void
    {
        $route = $this->route(['GET'], '/users/{id}');
        $match = $this->matcher([$route])->match($this->request('GET', '/users/a%2Fb'));

        self::assertSame(['id' => 'a%2Fb'], $match?->parameters());
    }

    public function test_query_string_does_not_participate_in_path_matching(): void
    {
        $route = $this->route(['GET'], '/users/{id}');
        $match = $this->matcher([$route])->match($this->request('GET', '/users/42'));

        self::assertSame(['id' => '42'], $match?->parameters());
    }

    public function test_exact_method_case_is_required(): void
    {
        $route = $this->route(['GET'], '/users');

        self::assertNull($this->matcher([$route])->match($this->request('get', '/users')));
    }

    public function test_path_match_with_wrong_method_continues_to_later_route(): void
    {
        $post = $this->route(['POST'], '/users/{id}');
        $get = $this->route(['GET'], '/users/{id}');
        $match = $this->matcher([$post, $get])->match($this->request('GET', '/users/42'));

        self::assertSame($get, $match?->route());
        self::assertSame(['id' => '42'], $match->parameters());
    }

    public function test_first_matching_route_in_insertion_order_wins(): void
    {
        $first = $this->route(['GET'], '/users/{id}');
        $second = $this->route(['GET'], '/users/{name}');
        $match = $this->matcher([$first, $second])->match($this->request('GET', '/users/me'));

        self::assertSame($first, $match?->route());
        self::assertSame(['id' => 'me'], $match->parameters());
    }

    public function test_static_routes_are_not_automatically_prioritized(): void
    {
        $parameter = $this->route(['GET'], '/users/{id}');
        $static = $this->route(['GET'], '/users/me');
        $match = $this->matcher([$parameter, $static])->match($this->request('GET', '/users/me'));

        self::assertSame($parameter, $match?->route());
        self::assertSame(['id' => 'me'], $match->parameters());
    }

    public function test_match_returns_exact_route_instance(): void
    {
        $route = $this->route(['GET'], '/users/{id}');

        self::assertSame($route, $this->matcher([$route])->match($this->request('GET', '/users/42'))?->route());
    }

    public function test_matching_does_not_invoke_handler(): void
    {
        $handler = $this->handler();
        $route = new Route(['GET'], '/users', $handler);

        self::assertSame($route, $this->matcher([$route])->match($this->request('GET', '/users'))?->route());
        self::assertSame(0, $handler->calls);
    }

    public function test_no_match_returns_null(): void
    {
        $route = $this->route(['GET'], '/users');

        self::assertNull($this->matcher([$route])->match($this->request('GET', '/accounts')));
        self::assertNull($this->matcher([$route])->match($this->request('POST', '/users')));
    }

    public function test_allowed_methods_returns_empty_list_for_no_matching_path(): void
    {
        $route = $this->route(['GET'], '/users');

        self::assertSame([], $this->matcher([$route])->allowedMethods('/accounts'));
    }

    public function test_allowed_methods_exposes_matching_path_route_methods(): void
    {
        $route = $this->route(['GET', 'POST'], '/users/{id}');

        self::assertSame(['GET', 'POST'], $this->matcher([$route])->allowedMethods('/users/42'));
    }

    public function test_allowed_methods_aggregates_multiple_matching_route_templates(): void
    {
        $first = $this->route(['GET'], '/users/{id}');
        $second = $this->route(['POST'], '/users/42');

        self::assertSame(['GET', 'POST'], $this->matcher([$first, $second])->allowedMethods('/users/42'));
    }

    public function test_allowed_methods_de_duplicates_in_first_seen_order(): void
    {
        $first = $this->route(['POST', 'GET'], '/users/{id}');
        $second = $this->route(['GET', 'PATCH'], '/users/42');

        self::assertSame(['POST', 'GET', 'PATCH'], $this->matcher([$first, $second])->allowedMethods('/users/42'));
    }

    public function test_allowed_methods_preserves_method_case(): void
    {
        $upper = $this->route(['GET'], '/users');
        $lower = $this->route(['get'], '/users');

        self::assertSame(['GET', 'get'], $this->matcher([$upper, $lower])->allowedMethods('/users'));
    }

    public function test_allowed_methods_has_no_implicit_head_fallback(): void
    {
        $route = $this->route(['GET'], '/users');

        self::assertNull($this->matcher([$route])->match($this->request('HEAD', '/users')));
        self::assertSame(['GET'], $this->matcher([$route])->allowedMethods('/users'));
    }

    public function test_allowed_methods_has_no_automatic_options(): void
    {
        $route = $this->route(['GET'], '/users');

        self::assertNull($this->matcher([$route])->match($this->request('OPTIONS', '/users')));
        self::assertSame(['GET'], $this->matcher([$route])->allowedMethods('/users'));
    }

    public function test_sequential_matching_starts_clean_on_every_call(): void
    {
        $first = $this->route(['POST'], '/users/{id}');
        $second = $this->route(['GET'], '/projects/{project}');
        $matcher = $this->matcher([$first, $second]);

        self::assertSame(['id' => '42'], $matcher->match($this->request('POST', '/users/42'))?->parameters());
        self::assertSame(['project' => 'alpha'], $matcher->match($this->request('GET', '/projects/alpha'))?->parameters());
    }

    public function test_parameters_from_one_request_do_not_leak_into_another(): void
    {
        $route = $this->route(['GET'], '/users/{id}');
        $matcher = $this->matcher([$route]);

        self::assertSame(['id' => '42'], $matcher->match($this->request('GET', '/users/42'))?->parameters());
        self::assertNull($matcher->match($this->request('GET', '/users/')));
        self::assertSame(['id' => '99'], $matcher->match($this->request('GET', '/users/99'))?->parameters());
    }

    /**
     * @param iterable<Route> $routes
     */
    private function matcher(iterable $routes): RouteMatcher
    {
        return new RouteMatcher(new RouteCollection($routes));
    }

    /**
     * @param list<string> $methods
     */
    private function route(array $methods, string $path): Route
    {
        return new Route($methods, $path, $this->handler());
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);

        return $request;
    }

    private function handler(): RecordingRouteHandler
    {
        return new RecordingRouteHandler($this->createStub(ResponseInterface::class));
    }

    private function assertInvalidRouteMethod(string $method): void
    {
        try {
            new Route([$method], '/users', $this->handler());
            self::fail('Expected invalid method token to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    private function assertInvalidRoutePath(string $path): void
    {
        try {
            new Route(['GET'], $path, $this->handler());
            self::fail('Expected invalid route path to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }
}

final class RecordingRouteHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function __construct(private readonly ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;

        return $this->response;
    }
}
