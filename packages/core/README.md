# EvolvePHP Core

Application kernel and runtime-neutral orchestration for EvolvePHP 2.

## Runtime Foundation

Phase 3.3 extends `Evolve\Core\ApplicationKernel` as the initial lifecycle implementation for the public `ApplicationLifecycle` contract, the first Core host for boot-time configuration validation and the integration point that freezes an optional service registry before readiness.

The current lifecycle remains intentionally minimal:

- a new kernel may boot once
- booting runs configured validators before readiness
- a booted kernel may shut down once
- invalid lifecycle transitions fail through the public lifecycle exception catch boundary
- configuration validation failures fail through the public configuration exception catch boundary
- a failed kernel instance is terminal; construct a new kernel to retry corrected startup

`Evolve\Core\Configuration\ArrayConfiguration` is an immutable array-backed configuration implementation created from already-materialized application values. It stores only scalar, null and recursive-array data, supports dot-path lookup through associative maps, treats missing values and explicit null values differently, and rejects objects, resources, malformed keys, ambiguous array maps and list-index path traversal.

`Evolve\Core\Container\ServiceRegistry` is the restricted bootstrap registration API for explicit service definitions. Calling `freeze()` is explicit and idempotent, returns a PSR-11 `Psr\Container\ContainerInterface`, prevents later registration and does not construct service instances. The frozen resolver is read-only: services are consumed through `has()` and `get()`, with application-lifetime services cached after the first successful construction and transient services created on every read.

Service identifiers follow PSR-11 opaque-string semantics: the empty string is invalid, while other strings are case-sensitive and not trimmed or normalized. `ServiceLifetime::Execution` is reserved vocabulary for later execution-scope work and is rejected during freeze in this phase. Circular dependencies, unknown services and ordinary factory failures are deterministic and catchable through PSR-11 exception interfaces; factory throwables are preserved as previous exceptions when wrapped.

This slice does not provide environment or dotenv loading, configuration files, autowiring, aliases, service tags, decorators, service-locator globals, execution-scope reset handling, HTTP handling, module/plugin runtime, console behavior or telemetry.

Core now depends on `evolvephp/contracts` and `psr/container`. The concrete lifecycle implementation, service definition model, frozen container implementation and internal state enum are not an invitation for consumers to depend on internal runtime classes.

## Package

`evolvephp/core`

## Requirements

PHP `^8.4`

## Dependencies

- `evolvephp/contracts`
- `psr/container`

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Installation

Independent Composer installation guidance will be added when package publication begins.

## Licence

BSD-3-Clause. See `LICENSE.md`.
