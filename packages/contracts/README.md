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

## Experimental Component Identity

Phase 5.1 adds experimental component identity vocabulary shared by future Module and Plugin metadata:

- `Evolve\Contracts\Component\ComponentIdentifier`
- `Evolve\Contracts\Component\ComponentType`

These APIs are public, pre-beta and marked `@experimental`. They are not stable public lifecycle APIs.

`ComponentIdentifier` accepts an explicitly supplied machine identifier and preserves the exact accepted string through `value()` and `__toString()`. It performs no normalization: accepted identifiers are not lowercased, trimmed or derived from filesystem paths, PHP class names, namespaces or Composer installation order.

The accepted identifier grammar is:

```text
identifier = side | side "/" side
side = alnum | alnum { alnum | "." | "_" | "-" } alnum
alnum = lowercase ASCII letter | ASCII digit
```

The grammar allows either `side` or `side/side`, with at most one `/`. Each side is non-empty, starts and ends with lowercase ASCII alphanumeric characters, and may contain lowercase ASCII alphanumeric characters, `.`, `_` or `-` internally. Accepted input is preserved exactly; no trimming, lowercasing or normalization occurs.

`ComponentType` is the experimental Module/Plugin identity vocabulary only. `ApplicationLifecycle` remains application lifecycle, and `ResetParticipant` remains execution reset. Phase 5.1 does not implement Module/Plugin lifecycle entry points; lifecycle, descriptor behavior, discovery, registration, boot, ready and shutdown behavior remain deferred.

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
