# EvolvePHP Core

Application kernel and runtime-neutral orchestration for EvolvePHP 2.

## Runtime Foundation

Phase 3.1 introduces `Evolve\Core\ApplicationKernel` as the initial lifecycle implementation for the public `ApplicationLifecycle` contract.

The current lifecycle is intentionally minimal:

- a new kernel may boot once
- a booted kernel may shut down once
- invalid lifecycle transitions fail through the public lifecycle exception catch boundary

This slice does not provide container integration, configuration, execution handling, reset participants, HTTP handling, console behavior or telemetry.

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
