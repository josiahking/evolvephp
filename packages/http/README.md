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

The package does not bundle a concrete PSR-7 implementation. Applications or later runtime slices must provide concrete request and response objects.

Routing, route matching, routed-handler dispatch, HTTP execution-kernel integration, response factories, runtime/SAPI request creation, response emission and OpenTelemetry propagation remain deferred to later Phase 4 slices.

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Installation

Independent Composer installation guidance will be added when package publication begins.

## Licence

BSD-3-Clause. See `LICENSE.md`.
