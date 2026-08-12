# EvolvePHP Contracts

Foundational public contracts for EvolvePHP 2.

## Public Contracts

Phase 3.4 extends the initial stable public contract surface:

- `Evolve\Contracts\Lifecycle\ApplicationLifecycle`
- `Evolve\Contracts\Configuration\Configuration`
- `Evolve\Contracts\Configuration\ConfigurationValidator`
- `Evolve\Contracts\Execution\ResetParticipant`
- `Evolve\Contracts\Exception\EvolveException`
- `Evolve\Contracts\Exception\LifecycleException`
- `Evolve\Contracts\Exception\ConfigurationException`

`ApplicationLifecycle` owns only the application boot/shutdown lifecycle. `Configuration` is read-only to consumers and exposes presence, retrieval, required-value and full-export lookup behavior. `ConfigurationValidator` is a small boot-time validation extension point that receives the read-only configuration and returns `void`. `ResetParticipant` exposes only `reset(): void` so reusable services can explicitly participate in deterministic execution-scope cleanup without depending on Core implementation types.

Consumers should catch public exception contracts such as `LifecycleException` and `ConfigurationException` instead of depending on Core concrete exception classes.

Configuration files, environment and dotenv loading, container access, service definitions, execution identifiers, execution orchestration, reset ordering policy, HTTP handling and framework runtime adapters are not part of these contracts.

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
