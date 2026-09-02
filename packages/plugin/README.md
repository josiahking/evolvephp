# EvolvePHP Plugin

Framework plugin SDK and lifecycle support for EvolvePHP 2.

## Descriptor Foundation

The package provides the public experimental `Evolve\Plugin\PluginDescriptor` in-memory immutable descriptor and `Evolve\Plugin\PluginCompatibilityValidator`.

`PluginDescriptor` reuses `Evolve\Contracts\Component\ComponentIdentifier`, preserves the exact accepted human-readable name, always reports the hard-coded Plugin type through `ComponentType::Plugin`, exposes descriptor schema version `1`, and declares an EvolvePHP major as a positive integer. Structural descriptor validity is separate from framework compatibility: positive non-2 majors are valid descriptor metadata, while `PluginCompatibilityValidator` performs explicit EvolvePHP major compatibility validation for the currently supported major `2`.

`PluginDescriptor` exposes `graphDeclaration()`, returning an immutable `Evolve\Contracts\Component\ComponentGraphDeclaration` built during descriptor construction. Existing three-argument descriptor construction remains valid and creates an empty graph declaration. A caller may also supply `ComponentGraphRelations` to declare required or optional dependencies, declarative conflicts, required capabilities with `ExactlyOne` or `OneOrMore` cardinality and provided capability identifiers.

The graph declaration is declaration vocabulary only. Relation ordering is canonical and deterministic, but declaration order has no startup-order semantics. It does not resolve dependencies, activate optional dependencies, resolve conflicts, select capability providers, detect cycles, discover components, register components, order boot or introduce component versions, version constraints or SemVer graph decisions. Dependency resolution and graph validation are handled by Core graph resolution.

These APIs are PUBLIC EXPERIMENTAL and pre-beta. Descriptor loading, descriptor serialization and non-Composer discovery remain deferred.

## Entry Point

The package provides the public experimental `Evolve\Plugin\Plugin` entry-point interface. `Plugin` extends `Evolve\Contracts\Component\ComponentEntryPoint` and introduces no Plugin-specific lifecycle methods.

An enabled plugin entry point participates in the shared component sequence: `register()` contributes service definitions through `ServiceDefinitionRegistrar`, Core freezes the service-definition graph, `boot()` receives an application-lifecycle-only `ComponentBootContext`, `ready()` runs after all enabled components boot, and `shutdown()` releases application-lifetime resources in reverse successful boot order. Deferred failure cleanup registered during `boot()` is only for that plugin's boot failure; successful plugins still clean up through normal shutdown.

## Explicit Definition

The package provides the public experimental `Evolve\Plugin\PluginDefinition`. It wraps an already-created `PluginDescriptor` and an explicit plugin entry-point class name.

`PluginDefinition` preserves the descriptor's identifier, type and exact graph declaration object. Its `validate()` method runs the existing `PluginCompatibilityValidator` and checks that the configured entry-point class exists and implements `Evolve\Plugin\Plugin`. Entry-point instances are created only when Core prepares an explicitly enabled definition for lifecycle execution.

Descriptor loading, automatic package scanning, plugin self-enablement, descriptor serialization and version-constraint evaluation remain deferred.

## Composer Plugin Discovery

The package provides the public experimental `Evolve\Plugin\Discovery\ComposerPluginDiscovery` for packaged plugins. The caller must supply the explicit local Composer 2 `installed.json` filesystem path, for example a known `vendor/composer/installed.json` path. Discovery does not infer `vendor/`, the project root, the current working directory or environment configuration.

Composer metadata is recognized at `extra.evolvephp.plugin`, and one Composer package exposes at most one discovered plugin. The Composer package name is the authoritative identifier; plugin metadata must not declare an `identifier` field. Discovery returns `PluginDefinition` instances sorted by package name, maps optional graph metadata into the existing Contracts graph declarations, and does not validate, instantiate or check entry-point classes while discovering metadata.

Schema 1 requires `schema`, `type`, `name`, `evolve_major` and `entry_point`. The optional `graph` object may contain `dependencies`, `conflicts`, `requires` and `provides`. Dependency `kind` values are `required` and `optional`; capability `cardinality` values are `exactly_one` and `one_or_more`.

```json
{
  "extra": {
    "evolvephp": {
      "plugin": {
        "schema": 1,
        "type": "plugin",
        "name": "Queue Plugin",
        "evolve_major": 2,
        "entry_point": "Vendor\\Queue\\QueuePlugin",
        "graph": {
          "dependencies": [
            { "component": "vendor/cache", "kind": "optional" }
          ],
          "conflicts": ["vendor/legacy-queue"],
          "requires": [
            { "capability": "logger", "cardinality": "exactly_one" }
          ],
          "provides": ["queue"]
        }
      }
    }
  }
}
```

Application-controlled enablement still belongs to Core through `evolve.components.enabled`. Disabled definitions remain inert, including incompatible Evolve major values and missing or wrong entry-point classes, until the application enables them through the existing `ComponentBootstrapper`.

Discovery is intentionally narrow: installed.json only, Composer 2 only, no recursive package scanning, no root composer.json application module discovery, no network discovery, no plugin self-enablement, no caching, no package publication, and no new Composer dependency or Composer runtime API requirement.

## Package

`evolvephp/plugin`

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
