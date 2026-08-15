# EvolvePHP Module

Application module SDK and lifecycle support for EvolvePHP 2.

## Phase 5.2 Descriptor Foundation

Phase 5.2 adds the public experimental `Evolve\Module\ModuleDescriptor` in-memory immutable descriptor and `Evolve\Module\ModuleCompatibilityValidator`.

`ModuleDescriptor` reuses `Evolve\Contracts\Component\ComponentIdentifier`, preserves the exact accepted human-readable name, always reports the hard-coded Module type through `ComponentType::Module`, exposes descriptor schema version `1`, and declares an EvolvePHP major as a positive integer. Structural descriptor validity is separate from framework compatibility: positive non-2 majors are valid descriptor metadata, while `ModuleCompatibilityValidator` performs explicit EvolvePHP major compatibility validation for the currently supported major `2`.

These APIs are PUBLIC EXPERIMENTAL and pre-beta. They are not stable lifecycle APIs.

Deferred from this slice:

- component versions, dependencies and capabilities remain deferred to Phase 5.3;
- registration remains deferred to Phase 5.4;
- entry-point contracts, lifecycle and boot remain deferred to Phase 5.5;
- discovery and enablement remain deferred to Phase 5.6;
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
