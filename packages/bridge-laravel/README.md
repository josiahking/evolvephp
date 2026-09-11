# EvolvePHP Bridge Laravel

`evolvephp/bridge-laravel` is the public experimental Laravel host adapter for embedded EvolvePHP 2 delegation. Laravel host Bridge adapter for embedded EvolvePHP 2 delegation. It requires PHP `^8.4`, supports the Illuminate 13.x HTTP and authentication contracts used by Laravel 13.x hosts, and its canonical source is the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

EvolvePHP 2 is pre-release, and this package is not yet independently published. The package is licensed under BSD-3-Clause; see `LICENSE.md`.

Dependencies: `evolvephp/bridge-contracts`, `evolvephp/bridge-psr`, `illuminate/contracts`, `illuminate/http`, `psr/http-factory` and `psr/http-message`.

## Scope

The adapter is embedded mode only. It composes the accepted Bridge contracts and same-process PSR adapter, then translates between a caller-owned `Illuminate\Http\Request` and a caller-owned PSR server request. The Laravel host keeps process lifecycle, application lifecycle, request acceptance, outer routing, container ownership and final response emission. Evolve owns delegated execution only after the host explicitly selects delegation.

The package does not provide automatic route registration, ServiceProvider lifecycle ownership, facade-based Evolve resolution, container merging, middleware interception, catch-all routing, remote fallback, retries, session sharing, cookie synchronization, uploaded-file bridging, streaming responses, binary/file responses, shared transactions, data migration, production cutover or rollback execution.

## Context And Identity

`LaravelBridgeContextFactory` creates the existing `BridgeContext`. The host supplies the request identifier, correlation identifier, tenant identifier, locale and timezone explicitly. It does not generate hidden identifiers and does not infer tenant identity from route parameters, request attributes, session state or globals.

Authenticated principal mapping is explicit and narrow. `Request::user()` returning `null` maps to an anonymous context. A non-null principal must implement Laravel's `Authenticatable` contract, and only a safe scalar authentication identifier is copied into `BridgeContext`. The Laravel user or model object itself, roles and permissions are not forwarded to Evolve.

## Request Translation

`LaravelBridgeAdapter` constructs a new PSR `ServerRequestInterface` through the supplied PSR-17 factory. It preserves the delegated method, URI, safe query data, safe parsed-body data and raw request body through the supplied stream factory. The server-parameter set is intentionally empty.

Only narrow interoperability headers are forwarded from the Laravel request: `accept`, `content-type`, `traceparent` and `tracestate`. Authorization headers, cookies, Laravel or Symfony request attributes, route objects, controller metadata, uploaded files, native session objects, server globals and host user/model objects are not forwarded. Identity travels through `BridgeContext`, not through host-provided identity headers.

If request translation cannot be performed safely, the adapter returns a non-retryable safe Bridge translation error and does not invoke Evolve.

## Response And Quarantine

The adapter invokes `EmbeddedBridgeAdapter` exactly once for a successfully translated request and returns a `LaravelBridgeResult`. A successful delegated PSR response is translated into `Illuminate\Http\Response` with the delegated status, body and a narrow response-header allowlist: `content-type`, `cache-control`, `etag`, `last-modified`, `location` and `retry-after`. `Set-Cookie` is not forwarded, and no session or cookie synchronization is attempted.

`LaravelBridgeResult` exposes an optional Laravel response, an optional safe `BridgeError`, `isReusable()` and `requiresQuarantine()`. A reset or quarantine error always implies `requiresQuarantine() === true`. Persistent-worker hosts such as Laravel Octane must treat `requiresQuarantine() === true` as process reuse being unsafe and connect that signal to their own worker lifecycle policy. This package does not recycle or terminate workers automatically and does not claim automatic Octane compatibility.

If response translation fails after Evolve already executed, the adapter returns a safe non-retryable translation failure. If the underlying Evolve execution already required quarantine, the Laravel-facing result preserves that quarantine signal even when no Laravel response can be produced.

## Representative Route Ownership

A bounded representative integration is a read-only Laravel route whose route definition explicitly selects Evolve delegation:

```php
$factory = new Evolve\Bridge\Laravel\LaravelBridgeContextFactory();

Route::get('/legacy/report-summary', function (Illuminate\Http\Request $request) use ($factory, $adapter) {
    $context = $factory->create(
        $request,
        requestIdentifier: (string) $request->headers->get('x-request-id'),
        correlationIdentifier: (string) $request->headers->get('x-correlation-id'),
        tenantIdentifier: 'tenant-123',
        locale: 'en_US',
        timezone: 'UTC',
    );

    $result = $adapter->invoke($request, $context);

    if ($result->requiresQuarantine()) {
        // The host records the unsafe reuse signal and applies its own worker policy.
    }

    return $result->response();
});
```

Laravel receives the outer request, and Laravel route configuration explicitly selects delegation. The forwarding route has no second authoritative implementation. Evolve owns delegated capability execution after the explicit boundary, while Laravel remains the final response emitter. The representative fixture performs no persistent write, so no shared transaction or data migration is implied. Authenticated principal mapping is explicit through `LaravelBridgeContextFactory`, tenant/locale/timezone values are explicit host inputs and quarantine is surfaced back to the host.

Production adoption must declare route and data ownership separately before cutover. Planning vocabulary such as migration manifests belongs to development tooling and is not a runtime dependency of this package.
