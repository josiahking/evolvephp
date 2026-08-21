# EvolvePHP Module

Application module SDK and lifecycle support for EvolvePHP 2.

## Phase 5.2 Descriptor Foundation

Phase 5.2 adds the public experimental `Evolve\Module\ModuleDescriptor` in-memory immutable descriptor and `Evolve\Module\ModuleCompatibilityValidator`.

`ModuleDescriptor` reuses `Evolve\Contracts\Component\ComponentIdentifier`, preserves the exact accepted human-readable name, always reports the hard-coded Module type through `ComponentType::Module`, exposes descriptor schema version `1`, and declares an EvolvePHP major as a positive integer. Structural descriptor validity is separate from framework compatibility: positive non-2 majors are valid descriptor metadata, while `ModuleCompatibilityValidator` performs explicit EvolvePHP major compatibility validation for the currently supported major `2`.

Phase 5.3A extends `ModuleDescriptor` with `graphDeclaration()`, returning an immutable `Evolve\Contracts\Component\ComponentGraphDeclaration` built during descriptor construction. Existing three-argument descriptor construction remains valid and creates an empty graph declaration. A caller may also supply `ComponentGraphRelations` to declare required or optional dependencies, declarative conflicts, required capabilities with `ExactlyOne` or `OneOrMore` cardinality and provided capability identifiers.

The graph declaration is declaration vocabulary only. Relation ordering is canonical and deterministic, but declaration order has no startup-order semantics. Phase 5.3A does not resolve dependencies, activate optional dependencies, resolve conflicts, select capability providers, detect cycles, discover components, register components, order boot or introduce component versions, version constraints or SemVer graph decisions.

These APIs are PUBLIC EXPERIMENTAL and pre-beta.

At the time of the Phase 5.2 descriptor foundation, graph validation and dependency resolution were deferred to Phase 5.3B, while registration, entry-point contracts, lifecycle and boot behavior were still deferred to later Phase 5 slices. Phase 5.5 now adds the shared entry-point interface while descriptor discovery still remains deferred.

## Phase 5.5 Entry Point

Phase 5.5 adds the public experimental `Evolve\Module\Module` entry-point interface. `Module` extends `Evolve\Contracts\Component\ComponentEntryPoint` and introduces no Module-specific lifecycle methods.

An enabled module entry point participates in the shared component sequence: `register()` contributes service definitions through `ServiceDefinitionRegistrar`, Core freezes the service-definition graph, `boot()` receives an application-lifecycle-only `ComponentBootContext`, `ready()` runs after all enabled components boot, and `shutdown()` releases application-lifetime resources in reverse successful boot order. Deferred failure cleanup registered during `boot()` is only for that module's boot failure; successful modules still clean up through normal shutdown.

Deferred from this slice:

- descriptor discovery, descriptor loading and enablement remain deferred to Phase 5.6;
- descriptor serialization remains deferred.

## Package

`evolvephp/module`

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
