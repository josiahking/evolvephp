# RFC 0002: Terminology, Package Boundaries and Public Contracts

- Status: Accepted
- Authors: Josiah Chinedu Gerald
- Created: 2026-07-28
- Target release: EvolvePHP 2.0
- Decision type: Package architecture and public API governance
- Depends on: RFC 0001
- Supersedes: None
- Superseded by: None

## 1. Summary

RFC 0002 establishes the package and namespace boundaries through which RFC 0001 will be implemented. It defines official terminology, first-party package direction, PHP namespace ownership, dependency rules and public API governance for EvolvePHP 2.

Product-area names and Composer package names are related but not identical. A product area may contain several Composer packages. A package boundary must represent a meaningful dependency and ownership boundary.

EvolvePHP 2 will begin in a modular monorepo. A monorepo does not permit uncontrolled cross-package dependencies.

This RFC defines direction and governance. It does not claim that the packages have already been created, published or implemented.

## 2. Terminology Hierarchy

### Product Family

The product family is the complete EvolvePHP ecosystem.

### Product Area

A product area is a named capability direction such as:

- Evolve Core.
- Evolve Modules.
- Evolve Plugins.
- Evolve Insight.
- Evolve Observe.
- Evolve Bridge.
- Evolve Runtime.
- Evolve Deploy.

A product area may contain one or more Composer packages.

### Composer Package

A Composer package is a separately versioned or publishable dependency boundary.

### PHP Namespace

A PHP namespace is the code-ownership boundary within a package.

### Application Module

An application module is an application-owned business capability.

### Framework Plugin

A framework plugin is an extension of framework or platform behaviour.

### Adapter

An adapter is an implementation connecting a framework contract to infrastructure, a host framework or a runtime.

### Contract

A contract is a documented public interface, value object, event, exception or behavioural agreement intended for external use.

### Internal Implementation

An internal implementation is code that may change without backward-compatibility guarantees.

## 3. Vendor and Namespace Policy

Adopted vendor and namespace roots:

```text
Composer vendor: evolvephp
Framework namespace root: Evolve\
```

Official Composer packages must use:

```text
evolvephp/<package-name>
```

Official framework namespaces must use:

```php
Evolve\<Area>\
```

Examples:

```php
Evolve\Contracts\
Evolve\Core\
Evolve\Http\
Evolve\Module\
Evolve\Plugin\
Evolve\Insight\
Evolve\Observe\
Evolve\Bridge\
Evolve\Runtime\
Evolve\Testing\
```

Rules:

- Third-party packages must use their own Composer vendor.
- Third-party plugins and modules must not place code under `Evolve\`.
- Application modules are not required to use an `Evolve\` namespace.
- Application code may use a structure such as:

```php
App\Modules\Billing\
App\Modules\Identity\
App\Modules\Reporting\
```

RFC 0002 does not require that exact application namespace. The framework must not claim ownership of application domain namespaces.

## 4. Initial First-Party Package Map

This section defines the accepted initial package direction. Package names are directional until repository/package-structure implementation work creates them.

### `evolvephp/contracts`

Namespace:

```php
Evolve\Contracts\
```

Responsibility:

- Stable foundational public interfaces.
- Shared public value contracts.
- Lifecycle contracts.
- Public framework exception contracts where justified.
- Small interoperability types.

Rules:

- Must remain small.
- Must not contain runtime implementations.
- Must not depend on EvolvePHP implementation packages.
- May depend only on PHP and carefully selected interoperability standards.
- Must not become a dumping ground for unrelated interfaces.

### `evolvephp/core`

Namespace:

```php
Evolve\Core\
```

Responsibility:

- Application kernel implementation.
- Container integration foundations.
- Configuration lifecycle.
- Boot, handle, terminate and reset orchestration.
- Core runtime coordination.

Dependencies:

- `evolvephp/contracts`.
- Carefully selected standards packages.

Core must not depend on optional product packages such as Bridge, Insight, Observe or Runtime adapters.

### `evolvephp/http`

Namespace:

```php
Evolve\Http\
```

Responsibility:

- HTTP lifecycle.
- Request and response integration.
- Routing.
- Middleware.
- HTTP-specific exceptions and adapters.

Dependencies may include:

- `evolvephp/contracts`.
- `evolvephp/core`.
- Selected PSR HTTP standards.

HTTP-specific types must not be placed in generic Core contracts unless they are genuinely framework-wide.

### `evolvephp/module`

Namespace:

```php
Evolve\Module\
```

Responsibility:

- Module SDK.
- Module descriptors.
- Module registration contracts.
- Module dependency declarations.
- Module discovery rules where approved.

This package defines framework support for application modules. It does not contain application business modules.

### `evolvephp/plugin`

Namespace:

```php
Evolve\Plugin\
```

Responsibility:

- Plugin SDK.
- Plugin metadata.
- Registration lifecycle contracts.
- Boot and shutdown lifecycle contracts.
- Plugin compatibility declarations.

This package does not automatically grant plugins access to internal framework services.

### `evolvephp/testing`

Namespace:

```php
Evolve\Testing\
```

Responsibility:

- Framework test helpers.
- Kernel test utilities.
- HTTP test utilities.
- Module and plugin test fixtures.
- Architecture-test utilities.

Rules:

- Production packages must never depend on `evolvephp/testing`.
- It is a development dependency.
- It may depend on multiple framework packages to provide testing integration.

### `evolvephp/insight`

Namespace:

```php
Evolve\Insight\
```

Responsibility:

- Local request diagnostics.
- Development inspection.
- Query, event, job and lifecycle collection.
- Diagnostic storage adapters intended for local or development use.
- Inspector UI or reporting integrations where later approved.

Rules:

- Insight is optional.
- Core must operate without Insight.
- Insight must consume public instrumentation hooks.
- Core must not contain Insight-specific storage logic.

### `evolvephp/observe`

Namespace:

```php
Evolve\Observe\
```

Responsibility:

- Production telemetry integration.
- OpenTelemetry integration.
- Trace, metric and log instrumentation.
- Context propagation.
- Exporter adapters.

Rules:

- Observe is optional.
- Observe must not depend on Insight.
- Insight must not be required for production telemetry.
- Observe should export telemetry rather than become a complete telemetry backend.

### Evolve Bridge Package Family

Use the package prefix:

```text
evolvephp/bridge-*
```

Potential packages include:

```text
evolvephp/bridge-contracts
evolvephp/bridge-psr
evolvephp/bridge-laravel
evolvephp/bridge-symfony
evolvephp/bridge-remote
```

These names establish direction, not a requirement to publish all packages in the alpha.

Rules:

- Each host-framework adapter must isolate its host dependency.
- Laravel dependencies must not enter generic Core or Symfony bridge packages.
- Symfony dependencies must not enter generic Core or Laravel bridge packages.
- Generic Bridge contracts must not depend on a host framework.
- Core must never depend on a Bridge adapter.

### Evolve Runtime Package Family

Use the package prefix:

```text
evolvephp/runtime-*
```

Potential packages include:

```text
evolvephp/runtime-cli
evolvephp/runtime-worker
evolvephp/runtime-frankenphp
evolvephp/runtime-roadrunner
```

Rules:

- Runtime-specific dependencies remain inside their adapter package.
- Core must not depend on FrankenPHP, RoadRunner or another runtime SDK.
- Persistent-runtime packages are not required for initial EvolvePHP 2.0 acceptance unless a later RFC changes that decision.

### `evolvephp/framework`

`evolvephp/framework` is a possible convenience metapackage. It may eventually depend on the standard packages needed for a typical application, such as:

```text
evolvephp/core
evolvephp/http
evolvephp/module
evolvephp/plugin
```

Rules:

- It must contain no runtime source code.
- It must not make optional packages such as Insight, Observe or framework bridges mandatory.
- Its exact dependency list will be decided during repository/package-structure implementation.

### Evolve Deploy

Evolve Deploy is a product area, not an initial Core package requirement.

Rules:

- Framework packages must not depend on Evolve Deploy.
- The open-source framework must remain usable without Evolve Deploy.
- Package naming for Evolve Deploy is reserved for a later RFC or product decision.

## 5. Modular Monorepo Policy

EvolvePHP 2 begins as a modular monorepo.

Reasons:

- Atomic architectural changes.
- Cross-package tests.
- Consistent tooling.
- Coordinated release preparation.
- Easier early-stage refactoring.
- Clear review of dependency relationships.

Rules:

- Each package must have a clear directory, namespace and dependency boundary.
- Direct file imports across packages are forbidden.
- Packages communicate through declared Composer dependencies and public APIs.
- A monorepo path does not make internal classes public.
- Circular Composer dependencies are forbidden.
- Package tests must be able to identify undeclared cross-package usage.
- Splitting packages into separate repositories is not required for EvolvePHP 2.0.
- Future repository splitting must not alter documented public contracts unnecessarily.

Do not create the monorepo structure in this task.

## 6. Dependency Direction

The accepted inward dependency direction starts with the most stable shared contracts:

```text
contracts
    ^
core
    ^
http

contracts
    ^
module

contracts
    ^
plugin
```

Optional packages may depend inward:

```text
insight  -> contracts, core, optional http integration
observe  -> contracts, core, selected telemetry standards
bridge-* -> contracts, core and the relevant host framework
runtime-* -> contracts, core and the relevant runtime SDK
testing  -> contracts, core, http, module and plugin
```

Forbidden directions:

```text
contracts -> core
contracts -> http
contracts -> module implementations
core -> insight
core -> observe
core -> bridge-*
core -> runtime-specific SDKs
core -> testing
http -> bridge-laravel
module -> application modules
plugin -> third-party plugins
observe -> insight
production package -> testing
```

Exact low-level dependency edges may be refined by later RFCs, but the inward dependency principle is accepted here.

## 7. Circular Dependency Prevention

- Composer package cycles are forbidden.
- Namespace cycles between package areas are forbidden.
- Mutual service lookups are not an acceptable substitute for clear dependency direction.
- Events do not automatically solve package cycles when both packages still require each other's types.
- Shared abstractions must move inward only when they represent a genuinely stable shared contract.
- Contracts must not be extracted merely to hide a poor dependency design.
- Optional integration belongs in adapter packages.
- Architecture tests should eventually enforce the accepted graph.

## 8. Module Ownership Rules

- Application modules are owned by the application.
- A module represents a business capability.
- Modules may depend on stable framework contracts.
- Modules should not depend on framework internal implementations.
- Modules should not directly depend on another module's internal classes.
- Cross-module interaction should use documented public application contracts, commands, queries, events or services.
- Module-owned data and behaviour remain within the module boundary.
- A module may be embedded, worker-hosted or remotely exposed only through adapters.
- RFC 0002 does not define the final module lifecycle API.
- RFC 0004 will define module and plugin lifecycle behaviour.

First-party reusable modules, when created, must use separately reviewed package names and public contracts.

## 9. Plugin Ownership Rules

- Plugins extend framework or platform behaviour.
- Plugins do not represent application business domains by default.
- Plugins may register services only through approved plugin contracts.
- Plugins must not reach into framework internal containers or global state.
- Plugins must declare compatibility.
- Plugins must not assume every optional framework package is installed.
- Host-specific plugins must isolate host dependencies.
- Plugin loading order and lifecycle will be defined in RFC 0004.
- Plugin code must use its own vendor namespace unless it is an official EvolvePHP package.

## 10. Public API Classifications

### Stable Public API

A stable public API:

- Is documented as public.
- Is covered by automated tests.
- Has defined behaviour.
- Follows semantic-versioning compatibility rules.
- Requires migration guidance when deprecated.
- Cannot be changed incompatibly in a minor or patch release.

`Evolve\Contracts\` types are public by default unless explicitly marked otherwise. Types in other namespaces are public only when documented as public.

### Experimental API

An experimental API:

- Must be labelled clearly in documentation.
- Should use `@experimental` in PHPDoc when implemented.
- May change before promotion to stable.
- Must not be presented as stable.
- Must not be placed in a stable contracts namespace without an explicit experimental designation.
- Should not be depended upon by stable first-party public contracts.

A minor release may change an experimental API, but the changelog must explain the change.

### Internal API

An internal API:

- Is not covered by backward-compatibility guarantees.
- Should use `@internal` when implemented.
- Must not be documented as a supported extension point.
- May change in minor or patch releases when public behaviour remains intact.

Internal code is not public merely because PHP permits access to it.

### Deprecated API

A deprecated API:

- Remains functional for a documented transition period.
- Must include a replacement or migration path.
- Must be recorded in the changelog.
- Should use `@deprecated` when implemented.
- Must not be removed in a patch release.
- Should normally remain for at least one minor release before removal.
- May be removed sooner only for a critical security or correctness reason, with explicit documentation.

## 11. What Counts As Public API

Public API may include:

- Documented interfaces.
- Documented classes intended for application use.
- Public value objects.
- Public exceptions callers are expected to catch.
- Documented configuration keys.
- Documented events.
- Documented attributes.
- Documented lifecycle hooks.
- Documented command-line behaviour.
- Documented package installation and integration behaviour.

Not automatically public:

- Every public PHP method.
- Internal constructors.
- Container service identifiers.
- Undocumented classes.
- Private configuration structure.
- Internal event payloads.
- Test fixtures.
- Package directory layout.
- Implementation-specific database schemas.
- Undocumented exception messages.

Namespace visibility and PHP visibility are not sufficient by themselves to establish support guarantees.

## 12. Public Contract Design Rules

Public contracts should follow these rules:

- Small, focused interfaces.
- Explicit inputs and outputs.
- No hidden dependency on global state.
- No service-locator requirements.
- No infrastructure implementation types in general contracts.
- Immutable value objects where appropriate.
- Typed exceptions where callers need recovery behaviour.
- No unnecessary framework base classes.
- Composition over inheritance.
- Public contracts before externally consumed implementations.
- Behavioural tests for every public contract.
- Documentation and changelog entries for material public API additions.

Do not require an interface for every class. Contracts must represent useful substitution, ownership or interoperability boundaries.

## 13. Interface Ownership

The consumer owns the abstraction when the abstraction exists to protect the consumer from an external implementation.

Rules:

- Infrastructure ports should normally live with the consumer or the stable contracts package.
- Adapter implementations live with the infrastructure or host integration package.
- A database adapter must not force database-specific types into domain-facing contracts.
- A Laravel bridge must not force Laravel types into generic bridge contracts.
- An OpenTelemetry adapter may use telemetry-standard types within the Observe boundary but must not leak exporter implementation types into unrelated packages.
- Shared contracts must not become a miscellaneous common package.

## 14. Package Version Alignment

- All first-party EvolvePHP 2 framework packages use major version `2`.
- Major versions must remain aligned across the official EvolvePHP 2 framework family.
- Stable public contracts must not require different incompatible framework majors.
- Minor releases may be coordinated where features span packages.
- Patch numbers do not need to be identical across every package.
- Package dependencies must declare explicit compatible constraints.
- A convenience metapackage may coordinate a tested package set.
- Package replacement or splitting must preserve documented public behaviour or use a migration path.
- Pre-release packages use semantic pre-release identifiers such as:

```text
2.0.0-alpha.1
2.0.0-beta.1
2.0.0-rc.1
```

Do not create releases or tags in this task.

## 15. Package Publication Policy

- Not every conceptual package must be published during the first alpha.
- Packages should be published only when their boundary is useful independently.
- Tiny packages must not be created solely for architectural appearance.
- Optional integrations should remain independently installable.
- Host-framework and runtime dependencies must remain isolated.
- A package must include documentation, tests, licence information and a defined support status before stable publication.
- Package names listed as directional or potential are reserved but not guaranteed to ship in 2.0.

## 16. Insight Versus Observe Boundary

### Insight

- Local and development diagnostics.
- Human-oriented framework inspection.
- May retain diagnostic batches locally.
- Optional.
- Must consume public instrumentation hooks.
- Must not be required for production telemetry.

### Observe

- Production telemetry integration.
- OpenTelemetry-oriented.
- Exports traces, metrics and logs.
- Optional.
- Must not depend on Insight.
- Must not become a full observability storage and query backend.

Shared instrumentation contracts may live inward in stable contracts or Core only when they are generic and useful to both systems. Insight-specific collectors must not be placed in Observe. Observe-specific exporters must not be placed in Insight.

## 17. Bridge Boundary

- Bridge adapters are outside Core.
- Generic Bridge contracts must not contain Laravel or Symfony types.
- Host-framework dependencies stay in their matching adapter package.
- The host application owns its top-level lifecycle in embedded mode.
- Bridge adapters translate between host behaviour and Evolve public contracts.
- Remote integration should use explicit transport contracts.
- Core must remain independently installable without Bridge packages.
- RFC 0006 will define the detailed Bridge lifecycle and integration contracts.

## 18. Runtime Boundary

- Core defines runtime-neutral lifecycle behaviour.
- Runtime adapters connect Core to specific execution environments.
- Runtime-specific request loops, worker APIs and shutdown hooks remain in runtime packages.
- Core must not import FrankenPHP or RoadRunner SDK types.
- Request reset behaviour must be exposed through runtime-neutral contracts.
- RFC 0005 will define request scope and reset safety.
- Persistent-runtime adapters remain deferred according to RFC 0001 unless later accepted decisions move them.

## 19. Testing Boundary

- Framework production packages must not depend on testing packages.
- Testing utilities may depend on production packages.
- Public contracts require behavioural tests.
- Package-boundary rules should eventually receive automated architecture tests.
- Tests must use public APIs unless intentionally testing internal behaviour.
- Fixtures intended only for tests are not stable public APIs.
- Test utilities intended for application developers must be documented and versioned.

## 20. Deploy Boundary

- Evolve Deploy is outside the framework runtime dependency graph.
- No framework package may require Deploy.
- Deployment metadata must consume framework health, configuration and runtime contracts rather than control framework internals.
- The open-source framework remains deployable without Evolve Deploy.
- Managed deployment decisions require a later RFC.

## 21. Architecture Enforcement Direction

Future enforcement mechanisms should include:

- Composer dependency validation.
- PSR-4 namespace validation.
- Architecture tests.
- Dependency-cycle detection.
- Public API snapshot or compatibility tooling.
- Static analysis.
- Package-level test suites.
- Cross-package integration tests.
- CI validation.
- Changelog checks.
- Documentation-policy tests.

Do not add these tools or CI workflows in this task.

## 22. Explicit Non-Goals

- This RFC does not create the package directories.
- It does not rewrite `composer.json`.
- It does not publish packages.
- It does not implement the kernel.
- It does not implement a container.
- It does not implement HTTP abstractions.
- It does not define the complete module lifecycle.
- It does not define the complete plugin lifecycle.
- It does not implement Bridge adapters.
- It does not implement runtime adapters.
- It does not implement Insight or Observe.
- It does not require every product area to become one Composer package.
- It does not require every class to have an interface.
- It does not make application modules use the `Evolve\` namespace.
- It does not guarantee package-level backward compatibility before an API is documented as stable.
- It does not split the repository into multiple repositories.
- It does not modify EvolvePHP 1 namespaces or dependencies.

## 23. Consequences and Tradeoffs

### Positive Consequences

- Clear package ownership.
- Smaller accidental public API surface.
- Replaceable optional integrations.
- Host and runtime dependencies remain isolated.
- Better future package publishing.
- Reduced circular-dependency risk.
- Clearer third-party extension rules.
- Stronger semantic-versioning discipline.
- Easier architecture-test enforcement.

### Negative Consequences

- More package and dependency-management overhead.
- Cross-package changes require more coordination.
- Public contract design takes additional time.
- A modular monorepo requires active enforcement.
- Too many packages could create fragmentation.
- Maintaining bridge and runtime adapters increases compatibility work.
- Experimental and internal API labelling requires discipline.

These costs are accepted as the price of explicit boundaries.

## 24. Alternatives Considered

### Keep One Large `evolvephp/evolvephp` Package

Rejected as the long-term EvolvePHP 2 package model because optional integrations and host dependencies would become tightly coupled.

A convenience distribution package may still exist.

### Create A Separate Repository For Every Package Immediately

Rejected for early development because it makes atomic architecture changes and cross-package testing harder.

### Put All Interfaces In One Large Contracts Package

Rejected because a contracts package can become an unowned dumping ground.

Only stable, foundational and genuinely shared contracts belong there.

### Let Every Package Depend On Core

Rejected because it creates central coupling and makes lightweight SDK or adapter use difficult.

### Treat Every Public PHP Method As Stable API

Rejected because language visibility does not define support guarantees.

### Allow Insight And Observe To Share One Implementation Package

Rejected because local developer diagnostics and production telemetry have different operational responsibilities.

## 25. Decision Governance

- RFC 0002 is authoritative for package and public API boundaries.
- Later RFCs may refine package contents without violating the accepted dependency direction.
- Material reversals require a superseding RFC.
- Accepted RFC 0002 must not be silently edited to permit circular dependencies or optional-to-core coupling.
- New official packages require documented ownership and dependency direction.
- New stable public APIs require documentation, tests and semantic-versioning review.
- Experimental APIs must be labelled.
- Internal implementations must not be marketed as stable extension points.
