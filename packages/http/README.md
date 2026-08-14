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
- `psr/http-factory`
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

Phase 4.4 adds HTTP execution-kernel integration through `Evolve\Http\HttpKernel`. `HttpKernel` is the outer HTTP execution boundary: it wraps an already-composed PSR-15 `RequestHandlerInterface`, delegates every `handle()` call to the Core `ExecutionOrchestrator` as `ExecutionKind::HttpRequest` and returns the resulting `ExecutionOutcome`.

`HttpKernel` does not itself implement PSR-15 because PSR-15 handlers must return `ResponseInterface`, while the execution boundary must preserve the full `ExecutionOutcome` so callers can inspect the primary response or throwable, cleanup/reset failure, instrumentation failures and the reuse/quarantine decision. The wrapped PSR-15 handler remains inside the execution.

During the Core operation, `HttpKernel` derives the request with authoritative request-local execution attributes: `ExecutionContext::class` contains the current immutable `ExecutionContext`, and `ExecutionScope::class` contains the current `ExecutionScope`. Stale incoming values at either key are replaced. These attributes are request-local integration data, not static current-execution state, process-wide registries or ambient service-locator APIs.

Existing `MiddlewarePipeline` and `RoutingRequestHandler` instances can run inside the same HTTP execution, so pre-routing middleware, route matching, post-match middleware and the final route handler observe the same execution context and scope. `ExecutionScope` is a low-level lifecycle integration boundary for explicit request-lifetime resources and reset participation; normal application dependencies should still prefer explicit constructor injection.

When the wrapped handler returns a response, that exact `ResponseInterface` instance remains the primary result. When the wrapped handler throws, the exact throwable remains the primary failure. Cleanup/reset failure stays separately observable on `ExecutionOutcome` and requires quarantine without replacing the primary response or primary throwable. Existing generic Core execution instrumentation is reused with the HTTP execution kind; no HTTP-specific telemetry, route observations, spans, logs, metrics, OpenTelemetry propagation or baggage handling is introduced.

Callers must inspect `ExecutionOutcome` and its reuse decision. `HttpKernel` does not emit a response, terminate a process, recycle a worker or convert failures into HTTP responses.

Phase 4.5 adds the response/error and health foundation. `Evolve\Http\Response\ExecutionOutcomeResponseResolver` converts only `ExecutionKind::HttpRequest` outcomes into PSR-7 `ResponseInterface` instances after `HttpKernel` has returned its `ExecutionOutcome`. Keeping response resolution after `HttpKernel` preserves the original primary response or throwable, cleanup/reset failure, instrumentation failures and reuse/quarantine decision for callers and runtime policy.

Successful HTTP outcomes must contain a `ResponseInterface`; the resolver returns that exact response instance without cloning, wrapping, rebuilding, changing headers or consulting the response factory. Non-HTTP outcomes are rejected as programming errors. A successful HTTP outcome with a non-response primary result is also rejected as an unexpected programming error.

Failed HTTP outcomes use the PSR-17 `ResponseFactoryInterface` from `psr/http-factory`. `RouteNotFound` maps to an empty `404` response. `MethodNotAllowed` maps to an empty `405` response with exactly one `Allow` header whose value is the exact `implode(', ', $exception->allowedMethods())` result, preserving method order and case without adding `HEAD` or `OPTIONS`. Any other primary `Throwable` maps to a generic empty `500` response without interpreting exception codes as HTTP status codes.

Cleanup failures, instrumentation failures and the process reuse/quarantine state remain stored on `ExecutionOutcome` and do not change response resolution. Runtime callers must still inspect the reuse decision. `ExecutionStartFailed` can happen before an `ExecutionOutcome` exists and remains a runtime concern rather than a resolver concern.

Phase 4.5 also adds explicit health-handler building blocks. `Evolve\Http\Health\LivenessHandler` is a PSR-15 handler that always returns an empty `200` response and performs no dependency checks. `Evolve\Http\Health\ReadinessCheck` is the minimal public readiness contract with `isReady(): bool`. `Evolve\Http\Health\ReadinessHandler` consumes and validates its check iterable during construction, preserves insertion order and returns an empty `200` response for zero checks or all-ready checks. The first false or throwing readiness check short-circuits later checks and returns an empty `503` response.

Health handlers are not auto-routed and no health paths or route names are reserved. Applications explicitly place `LivenessHandler` or `ReadinessHandler` in whichever route definitions they choose.

Framework-created Phase 4.5 responses are intentionally empty and bodyless. Error and health responses do not expose request data, route parameters, exception messages, traces, readiness-check failure details or other sensitive runtime data. The only required protocol metadata added here is the `Allow` header for 405 responses.

The package does not bundle a concrete PSR-7 implementation. Applications or later runtime slices must provide concrete request and response objects. Phase 4.5 requires only `ResponseFactoryInterface`; it does not use `StreamFactoryInterface`.

HTML and JSON error rendering, problem-details DTOs, content negotiation, debug pages, automatic health routes, SAPI request creation, response emission, process termination/recycle adapters, runtime adapters and OpenTelemetry propagation remain deferred to later reviewed slices.

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Installation

Independent Composer installation guidance will be added when package publication begins.

## Licence

BSD-3-Clause. See `LICENSE.md`.
