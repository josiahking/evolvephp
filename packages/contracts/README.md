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

## Experimental Component Graph Declarations

Phase 5.3A adds public experimental declaration vocabulary for component dependency, conflict and capability metadata:

- `Evolve\Contracts\Component\ComponentGraphDeclaration`
- `Evolve\Contracts\Component\ComponentGraphRelations`
- `Evolve\Contracts\Component\ComponentDependency`
- `Evolve\Contracts\Component\ComponentDependencyKind`
- `Evolve\Contracts\Component\ComponentConflict`
- `Evolve\Contracts\Component\CapabilityIdentifier`
- `Evolve\Contracts\Component\CapabilityRequirement`
- `Evolve\Contracts\Component\CapabilityCardinality`

These APIs are public, pre-beta and marked `@experimental`. They are not stable lifecycle APIs and they do not resolve or execute the graph.

`ComponentGraphDeclaration` is a narrow immutable projection for one component. It preserves the supplied `ComponentIdentifier` and `ComponentGraphRelations` objects, rejects self-dependency and self-conflict, and does not perform global graph validation.

`ComponentGraphRelations` stores immutable lists of dependencies, conflicts, required capabilities and provided capabilities. Dependencies are declarative and can be `ComponentDependencyKind::Required` or `ComponentDependencyKind::Optional`. Conflicts are declarative component targets. Required capabilities combine a `CapabilityIdentifier` with `CapabilityCardinality::ExactlyOne` or `CapabilityCardinality::OneOrMore`; provided capabilities are represented only as provided capability identifiers.

Relation lists are runtime-validated for list shape and element type, reject duplicate or contradictory declarations, and are exposed in canonical `strcmp()` identifier order. Declaration order has no startup-order semantics.

`CapabilityIdentifier` accepts an explicitly supplied lowercase ASCII capability identifier and preserves the exact accepted string through `value()` and `__toString()`. It performs no trimming, lowercasing or normalization. The accepted identifier grammar is:

```text
capability = alnum | alnum { alnum | "." | "_" | "-" } alnum
alnum = lowercase ASCII letter | ASCII digit
```

Phase 5.3A does not implement dependency resolution, missing dependency errors, optional dependency activation, conflict resolution, capability-provider indexing or selection, ambiguity resolution, cycle detection, startup ordering, component versions, version constraints or Composer SemVer graph decisions. Phase 5.3B owns graph validation and resolution behavior; later Phase 5 slices own registration, lifecycle and discovery.

## Experimental Component Service Registration

Phase 5.4 adds `Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar` as a public experimental, contribution-only service-definition boundary. Components may contribute Application, Execution or Transient service definitions through the three explicit registration methods only.

Factories are documented as accepting zero or one active PSR-11 resolver argument. This gives Contracts deliberate external-standard access to `Psr\Container\ContainerInterface` for the public factory contract, but Contracts remains first-party-inward and is not a container implementation.

Core owns the restricted registrar implementation, registration ordering, staging and atomic publication. Registration order comes from `ResolvedComponentGraph`, service definitions are staged before publication, registration failure publishes nothing, registration does not resolve or construct services, and registration does not freeze the registry. `ApplicationKernel` integration, entry-point lifecycle, boot and ready remain Phase 5.5; discovery and enablement remain Phase 5.6.

## Package

`evolvephp/contracts`

## Requirements

PHP `^8.4`

## Dependencies

First-party EvolvePHP dependencies: None.

External dependencies:

- `psr/container`

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Installation

Independent Composer installation guidance will be added when package publication begins.

## Licence

BSD-3-Clause. See `LICENSE.md`.
