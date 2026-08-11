# EvolvePHP Core

Application kernel and runtime-neutral orchestration for EvolvePHP 2.

## Runtime Foundation

Phase 3.2 extends `Evolve\Core\ApplicationKernel` as the initial lifecycle implementation for the public `ApplicationLifecycle` contract and the first Core host for boot-time configuration validation.

The current lifecycle remains intentionally minimal:

- a new kernel may boot once
- booting runs configured validators before readiness
- a booted kernel may shut down once
- invalid lifecycle transitions fail through the public lifecycle exception catch boundary
- configuration validation failures fail through the public configuration exception catch boundary
- a failed kernel instance is terminal; construct a new kernel to retry corrected startup

`Evolve\Core\Configuration\ArrayConfiguration` is an immutable array-backed configuration implementation created from already-materialized application values. It stores only scalar, null and recursive-array data, supports dot-path lookup through associative maps, treats missing values and explicit null values differently, and rejects objects, resources, malformed keys, ambiguous array maps and list-index path traversal.

This slice does not provide environment or dotenv loading, configuration files, container or PSR-11 integration, service definitions, execution handling, reset participants, HTTP handling, module/plugin runtime, console behavior or telemetry.

Core continues to depend only on `evolvephp/contracts`. The concrete lifecycle implementation and its internal state enum are not an invitation for consumers to depend on internal lifecycle-state classes.

## Package

`evolvephp/core`

## Requirements

PHP `^8.4`

## Dependencies

`evolvephp/contracts`

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Installation

Independent Composer installation guidance will be added when package publication begins.

## Licence

BSD-3-Clause. See `LICENSE.md`.
