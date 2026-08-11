# EvolvePHP Contracts

Foundational public contracts for EvolvePHP 2.

## Public Contracts

Phase 3.1 introduces the first stable public contract surface:

- `Evolve\Contracts\Lifecycle\ApplicationLifecycle`
- `Evolve\Contracts\Exception\EvolveException`
- `Evolve\Contracts\Exception\LifecycleException`

`ApplicationLifecycle` owns only the application boot/shutdown lifecycle. Execution handling, reset behavior, container access, configuration and framework runtime adapters are not part of this contract.

Consumers should catch public exception contracts such as `LifecycleException` instead of depending on Core concrete exception classes.

## Package

`evolvephp/contracts`

## Requirements

PHP `^8.4`

## Dependencies

None.

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Installation

Independent Composer installation guidance will be added when package publication begins.

## Licence

BSD-3-Clause. See `LICENSE.md`.
