# EvolvePHP HTTP

HTTP lifecycle, routing and middleware foundations for EvolvePHP 2.

## Package

`evolvephp/http`

## Requirements

PHP `^8.4`

## Dependencies

`evolvephp/contracts`, `evolvephp/core`

This package also consumes the selected external PSR HTTP interoperability interfaces:

- `psr/http-message`
- `psr/http-server-handler`
- `psr/http-server-middleware`

## Runtime Foundation

Phase 4.1 provides a deterministic PSR-15 middleware pipeline foundation through `Evolve\Http\Middleware\MiddlewarePipeline`.

The pipeline consumes PSR-7 `ServerRequestInterface` and `ResponseInterface` objects and implements PSR-15 `RequestHandlerInterface`. Middleware entries must implement PSR-15 `MiddlewareInterface`.

Middleware runs in the order supplied at construction. For middleware `A`, middleware `B` and terminal handler `T`, execution is `A before`, `B before`, `T`, `B after`, `A after`. Middleware may short-circuit by returning a response without invoking the next handler; remaining middleware and the terminal handler are then not executed.

Phase 4.2 adds the routing foundation through `Evolve\Http\Routing\Route`, `RouteCollection`, `RouteMatch` and `RouteMatcher`.

Routes store an exact ordered method list, a path-only template and a PSR-15 request handler instance. The handler is retained as route-definition data only; route matching does not invoke it.

Route templates support static path segments and whole-segment `{parameter}` captures such as `/users/{id}`. Matching is deterministic and segment-based, preserves the original template as route identity, compares paths exactly, does not URL-decode captured values and does not trim trailing slashes, collapse repeated slashes or normalize case.

HTTP method matching is exact and case-sensitive. Methods remain as supplied, `GET` and `get` are distinct, `HEAD` is not implied by `GET` and `OPTIONS` is not generated automatically.

`RouteMatcher` traverses routes in collection insertion order. The first route whose path template and method both match wins; static routes are not automatically prioritized over parameter routes. If a path template matches but the method does not, matching continues to later routes. `allowedMethods()` exposes path-level routing metadata by aggregating exact methods for matching templates without creating 405 responses or `Allow` headers.

Phase 4.3 adds routed handler dispatch through `Evolve\Http\Routing\RoutingRequestHandler`, a PSR-15 `RequestHandlerInterface` implementation. It accepts a `RouteMatcher` and optional ordered post-match middleware. Existing `MiddlewarePipeline` instances may wrap `RoutingRequestHandler` for pre-routing/global middleware, while middleware supplied directly to `RoutingRequestHandler` runs only after a successful route match.

On successful matching, `RoutingRequestHandler` attaches the exact authoritative `RouteMatch` to the derived request with `RouteMatch::class` as the request attribute key, replacing any stale incoming value at that key. Route parameters remain inside `RouteMatch::parameters()` and are not injected as top-level request attributes. Dispatch then runs post-match middleware in order and terminates at the exact `Route::handler()` instance stored on the matched route.

Unsuccessful routing now has typed public exception boundaries. `Evolve\Http\Exception\RouteNotFound` is thrown when no path template matches, and `Evolve\Http\Exception\MethodNotAllowed` is thrown when the path matches at least one route template but the request method does not match any route. `MethodNotAllowed::allowedMethods()` returns the exact allowed methods reported by `RouteMatcher`, preserving order and case without adding implicit `HEAD`, automatic `OPTIONS` or an `Allow` header.

The package does not bundle a concrete PSR-7 implementation. Applications or later runtime slices must provide concrete request and response objects.

404/405 response creation, exception-to-response rendering, `Allow` header generation, PSR-17 response factories, HTTP execution-kernel integration, runtime/SAPI request creation, response emission and OpenTelemetry propagation remain deferred to later Phase 4 slices.

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Installation

Independent Composer installation guidance will be added when package publication begins.

## Licence

BSD-3-Clause. See `LICENSE.md`.
