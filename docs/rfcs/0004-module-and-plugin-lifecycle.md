# RFC 0004: Module and Plugin Lifecycle

- Status: Accepted
- Authors: Josiah Chinedu Gerald
- Created: 2026-07-28
- Target release: EvolvePHP 2.0
- Decision type: Module, plugin and application lifecycle architecture
- Depends on: RFC 0001, RFC 0002, RFC 0003
- Supersedes: None
- Superseded by: None

## 1. Summary

RFC 0004 defines how EvolvePHP 2 modules and plugins move through:

```text
discovery
validation
dependency resolution
registration
boot
ready
shutdown
```

A module represents an application business capability. A plugin extends framework or platform behaviour. Modules and plugins are not interchangeable terms, even though both participate through explicit lifecycle contracts.

This RFC defines lifecycle behaviour and ordering. It does not define concrete PHP interface signatures, descriptor file formats, registries, containers or loader implementations.

## 2. Goals

- Deterministic startup.
- Explicit dependency declarations.
- Fail-fast validation.
- No hidden global state.
- No uncontrolled service locator.
- No arbitrary code execution during discovery.
- Clear separation between registration and boot.
- Optional components remain optional.
- Predictable cleanup.
- Compatibility with modular monoliths.
- Future compatibility with workers and remote adapters.
- Testable lifecycle behaviour.
- Generic instrumentation hooks without coupling to Insight or Observe.

## 3. Terminology

### Module

An application-owned business capability with explicit public boundaries.

### Plugin

A framework or platform extension that participates through approved extension contracts.

### Descriptor

Immutable metadata describing a module or plugin without requiring the component to execute.

### Entry Point

The lifecycle participant instantiated after successful discovery and validation.

### Registration

The phase where service definitions, routes, listeners, commands or other contributions are declared.

### Boot

The phase where a registered component performs initialization that requires the completed application service graph.

### Application Ready

The state after all enabled components have registered and booted successfully.

### Shutdown

Orderly release of application-lifetime resources.

### Reset

Clearing execution-scoped state between requests, messages or jobs. Detailed reset semantics belong to RFC 0005.

### Capability

A named framework or application facility that may be required or provided without coupling to a specific concrete provider.

### Required Dependency

A dependency whose absence prevents application startup.

### Optional Dependency

A dependency that may alter integration when present but whose absence does not prevent startup.

### Conflict

An explicitly incompatible component, capability provider or version combination.

## 4. Lifecycle Overview

EvolvePHP 2 adopts this lifecycle:

```text
1. Discover descriptors
2. Select enabled components
3. Validate descriptors and compatibility
4. Build dependency and capability graph
5. Produce deterministic order
6. Register components
7. Freeze the service-definition graph
8. Boot components
9. Mark the application ready
10. Handle application work
11. Shut down components in reverse order
```

Rules:

- Discovery and validation occur before component entry points execute.
- No request, message or job may be handled before ready state.
- Registration and boot are separate phases.
- Shutdown does not mean dynamic hot unloading.
- Reset occurs within repeated execution lifecycles and is specified by RFC 0005.

## 5. Descriptor Policy

Every module and plugin must have a descriptor containing enough immutable metadata to validate the application before execution.

The descriptor direction must support:

- Stable identifier.
- Human-readable name.
- Component type: module or plugin.
- Component version where applicable.
- Descriptor schema version.
- Entry-point class or factory reference.
- Required dependencies.
- Optional dependencies.
- Declared conflicts.
- Required capabilities.
- Provided capabilities.
- EvolvePHP compatibility constraint.
- Configuration namespace or schema reference.
- Discovery source.
- Enabled state supplied by application configuration, not self-selected.

Rules:

- Descriptor parsing must not instantiate the component.
- Descriptor parsing must not resolve application services.
- Descriptor parsing must not make network calls.
- Descriptor parsing must not mutate global state.
- Composer constraints remain authoritative for PHP and package dependency compatibility.
- Descriptor metadata must not contradict Composer metadata.
- Exact serialization format is deferred to implementation work.

## 6. Identifier Policy

- Every enabled module and plugin has one stable machine identifier.
- Identifiers are unique within the application.
- Duplicate identifiers are fatal.
- Identifier comparison is deterministic and case-sensitive or normalized consistently.
- Identifiers must not depend on filesystem paths.
- Renaming a stable published identifier requires migration guidance.
- A packaged plugin may use its Composer package name as its identifier.
- An application module may use an application-defined identifier such as:

```text
billing
identity
acme.reporting
```

- Display names are not identifiers.
- The framework must not derive identity from PHP class short names alone.

RFC 0004 does not lock a serialization format beyond requiring a safe, documented identifier grammar.

## 7. Discovery Policy

Explicit discovery is the baseline.

Supported directions may include:

- Explicit application configuration.
- Composer package metadata.
- A generated or cached manifest.
- Host-framework bridge configuration.

Rules:

- Explicit registration must always be supported.
- Recursive filesystem scanning is not the default.
- The framework must not instantiate every discovered class to determine whether it is a module or plugin.
- Discovery results must be deterministic for the same application configuration and dependency set.
- Auto-discovery, when enabled, must resolve to validated descriptors.
- Discovery caches must not become the source of truth.
- A stale cache must be invalidated when relevant configuration or dependency metadata changes.
- Discovery source does not grant elevated privileges.

Discovery is not implemented by this RFC.

## 8. Enablement And Disablement

- Application configuration controls which optional modules and plugins are enabled.
- A component must not enable itself.
- Disabled components are not registered or booted.
- A required dependency on a disabled component is a startup error.
- Disabling a required provider must fail validation.
- Enablement is fixed for a completed application boot.
- Runtime self-enablement is forbidden.
- Per-request or per-tenant module enablement is outside the initial lifecycle.
- A disabled component's code may still be installed through Composer without being active.
- Production systems must not silently auto-enable newly installed plugins.

## 9. Compatibility Validation

Before registration, EvolvePHP validates:

- Unique identifier.
- Recognized descriptor schema.
- Supported EvolvePHP major and lifecycle-contract version.
- Required PHP and Composer compatibility through package metadata.
- Entry-point validity.
- Required dependencies.
- Optional dependency constraints when present.
- Conflicts.
- Required capabilities.
- Capability-provider ambiguity.
- Dependency cycles.
- Configuration validity.
- Component type.

Rules:

- Invalid enabled components prevent startup.
- Compatibility errors must identify the component and failed constraint.
- Validation must happen before side effects.
- A warning must not replace a required compatibility failure.
- Unsupported components must not be booted optimistically.

## 10. Dependency Declarations

Required dependencies:

- Must be explicit.
- Must identify the required component or stable public contract.
- Must define an acceptable compatibility range where versioning applies.
- Create dependency-ordering edges.
- Must be present and enabled.

Optional dependencies:

- Do not prevent startup when absent.
- Must be validated when present.
- May create an ordering edge when the integration is active.
- Must not be accessed without presence detection through an approved contract.
- Must not become hidden required dependencies.

Rules:

- Components must not infer dependencies by resolving arbitrary service identifiers.
- Components must not depend on another component's internal implementation.
- Module-to-module interaction uses public application contracts.
- Generic plugins must not depend on application-module internals.
- Host-specific integration belongs in adapter packages.

## 11. Capability Requirements And Providers

Capability-based integration is an alternative to concrete-provider coupling.

Examples include:

```text
cache
queue
events
telemetry
filesystem
mailer
```

Rules:

- Components may declare required and provided capabilities.
- A capability name is not a container service identifier.
- Required capabilities must have a valid provider.
- Provider compatibility must be validated.
- When exactly one provider is required, multiple unselected providers are an ambiguity error.
- Applications may explicitly select a provider.
- Multi-provider capabilities must be declared as such.
- A capability must not become an untyped global registry.
- Core must not depend on a particular optional provider.
- Capabilities do not bypass package dependency declarations.

## 12. Dependency Graph

The framework constructs a directed graph after validation.

Rules:

- Dependencies point from the consumer to the requirement.
- Required dependencies participate in the graph.
- Present and active optional integrations may participate in the graph.
- Required capability providers participate in ordering.
- The graph must be acyclic.
- Dependency cycles are fatal.
- Cycle errors must report the detected dependency chain.
- A service locator or event bus must not be used to disguise a package or lifecycle cycle.
- Extracting a meaningless shared interface is not an acceptable cycle fix.

## 13. Deterministic Ordering

- Dependencies register and boot before their dependents.
- Components with no ordering relationship use a documented stable tie-breaker, such as normalized identifier order.
- The same descriptor set and configuration must produce the same order.
- Filesystem enumeration order must never determine lifecycle order.
- Composer installation order must not be relied upon implicitly.
- Arbitrary numeric startup priority is not part of the foundational lifecycle.
- When order is semantically required, declare a dependency or capability relationship.
- Listener or middleware priority is separate from module/plugin startup ordering.

## 14. Registration Phase

Registration declares application structure.

Permitted contributions may include:

- Service definitions.
- Aliases and decorators.
- Routes.
- Middleware.
- Events and listeners.
- Commands.
- Scheduled-task definitions.
- Configuration schemas.
- Reset participants.
- Diagnostic or instrumentation contributors.

Rules:

- Registration uses a restricted registration contract or builder.
- Registration must not receive uncontrolled access to a mutable application container.
- Components must not resolve runtime services during registration.
- Registration must not start workers.
- Registration must not open long-lived network connections.
- Registration must not read request globals.
- Registration must not perform application business operations.
- Contributions must be deterministic for the same configuration.
- Duplicate or conflicting contributions must follow explicit conflict rules.
- Silent last-write-wins replacement is forbidden for protected registrations.
- Decoration and multi-binding require explicit supported contracts.

## 15. Registration Failure

- Registration failure aborts application startup.
- Components after the failed component are not registered.
- Boot does not begin.
- The unpublished service-definition graph is discarded.
- No partially compiled application is marked ready.
- The error must identify the failing component and lifecycle phase.
- Registration should avoid external resources so rollback remains simple.
- Any cleanup registered by the registration infrastructure must run best-effort.
- Cleanup errors must not hide the original registration error.

## 16. Service-Definition Freeze

After successful registration:

- The service-definition graph is validated.
- Required services and aliases are checked where possible.
- Protected definition conflicts are resolved or rejected.
- The definition graph is frozen before boot.
- Runtime mutation of the compiled definition graph is forbidden by default.
- Boot may resolve services but must not rewrite application structure.
- A later approved extension mechanism must preserve deterministic behaviour.
- Freezing does not imply that every service instance is created eagerly.

The exact container implementation remains outside RFC 0004.

## 17. Boot Phase

Boot occurs after all registrations succeed and the definition graph is frozen.

Boot may:

- Resolve required application services.
- Initialize application-lifetime resources.
- Register external callbacks that cannot be declared earlier.
- Verify external connectivity when explicitly required.
- Prepare caches or metadata needed before ready state.

Rules:

- Components boot in deterministic dependency order.
- Boot is called at most once per application boot.
- Boot must not process user requests, messages or jobs.
- Boot must not store request-specific state in application-lifetime objects.
- Resources created during boot must have a cleanup strategy.
- Components should avoid slow or unreliable external work unless readiness genuinely requires it.
- Optional external systems must not accidentally become mandatory through boot logic.
- A component is considered booted only after its boot operation completes successfully.

## 18. Boot Failure And Cleanup

- Boot failure aborts application readiness.
- No application work may be accepted.
- Successfully booted components shut down in reverse boot order.
- The component whose boot operation failed is not considered successfully booted.
- The boot context should support registering cleanup for partially created resources; exact API is deferred.
- Shutdown continues even when one cleanup fails.
- Cleanup failures are collected and reported.
- The original boot failure remains the primary error.
- Components not yet booted do not receive normal shutdown.
- The application must not continue in a partially ready state.

## 19. Ready State

The application becomes ready only when:

- Discovery completed.
- Validation passed.
- Dependency ordering succeeded.
- All enabled components registered.
- The definition graph froze successfully.
- All enabled components booted successfully.

Rules:

- Readiness must be explicit.
- Health reporting must distinguish booting, ready, shutting down and failed states.
- A process being alive does not prove it is ready.
- Readiness hooks must not allow late structural mutation.
- Insight and Observe may consume generic lifecycle instrumentation but are not required for readiness.

## 20. Shutdown Phase

- Shutdown runs in reverse successful boot order.
- A dependent shuts down before its dependency.
- Shutdown is best-effort.
- One shutdown failure must not prevent remaining shutdown operations.
- All failures must be reported.
- Shutdown should release application-lifetime resources.
- Shutdown must not be used as ordinary business-event processing.
- Repeated shutdown invocation must be prevented or safely ignored according to the future lifecycle implementation.
- Shutdown does not provide hot plugin unloading.
- Process termination signals and host-framework shutdown integration belong to Runtime and Bridge adapters.

## 21. At-Most-Once Lifecycle Invocation

For one application boot:

- Each enabled entry point is registered at most once.
- Each successfully registered component is booted at most once.
- Each successfully booted component is shut down at most once.
- Reentrant lifecycle invocation is an error.
- Duplicate descriptor discovery must not result in duplicate lifecycle execution.
- Lifecycle implementation must expose state sufficient to reject invalid transitions.
- Exact state-machine classes are deferred.

Suggested conceptual states:

```text
discovered
validated
ordered
registered
booted
ready
shutting-down
stopped
failed
```

RFC 0004 does not require a public mutable status setter.

## 22. Configuration Boundary

- Module and plugin configuration is namespaced.
- Configuration is validated before registration or boot.
- Components receive configuration through approved contracts.
- Components should not read environment variables or global configuration arrays directly.
- Defaults, required values and validation rules must be documented.
- Unknown configuration handling must be deterministic.
- Secrets must not be included in descriptors, error dumps or diagnostics.
- Configuration changes that alter the active component graph require application rebuild or restart.
- Per-request configuration belongs to execution scope, not application lifecycle.

The full configuration system is outside this task.

## 23. Container Access

- Registration receives a restricted registrar or definition builder.
- Boot may receive a read-only resolver or boot context.
- Components must not access the container through global state.
- Components must not retain an unrestricted mutable container reference.
- Service location must not replace explicit constructor dependencies.
- A plugin does not gain access to every internal service merely because it participates in lifecycle.
- Internal service identifiers are not stable extension contracts.
- Public extension points must be documented under RFC 0002 rules.

## 24. Module-Specific Rules

Modules:
- Represent business capabilities.
- Are application-owned unless published as explicit reusable packages.
- Own their domain behaviour and data boundaries.
- May expose public application contracts.
- Must not expose internal classes as cross-module APIs.
- May contribute HTTP, CLI, event or worker adapters.
- Must keep domain logic independent of delivery mechanism.
- May depend on framework contracts.
- Must not depend on framework internals.
- Must not depend on a plugin's concrete implementation when a capability contract is sufficient.
- Do not become plugins merely because they register services.

## 25. Plugin-Specific Rules

Plugins:
- Extend framework or platform behaviour.
- Must declare their compatibility.
- Register only through approved extension contracts.
- Must not mutate internal framework state directly.
- Must not assume every optional package exists.
- Must isolate host-framework or runtime dependencies.
- Must not use application-domain internals unless the application explicitly owns an integration adapter.
- Must not self-enable.
- Must not perform discovery-time side effects.
- Are trusted in-process code once installed and enabled.
- Are not sandboxed by the lifecycle system.

## 26. Trust And Security Boundary

An installed and enabled in-process PHP plugin is trusted code and can execute with the privileges of the application process.

Rules:

- The lifecycle system is not a security sandbox.
- Descriptor validation does not make untrusted PHP safe.
- Applications must review plugin provenance and dependencies.
- Plugins should receive only documented framework capabilities, but PHP process access remains a trust decision.
- Untrusted extension execution requires process or service isolation.
- Remote plugins or marketplace security require separate governance.
- Secret access must follow application service boundaries.
- Plugins must not bypass authentication, authorization or tenant boundaries through internal access.
- Security-sensitive extension points require explicit review.

## 27. Persistent-Runtime Boundary

- Modules and plugins are application-lifetime participants by default.
- Application-lifetime instances must not retain request, message, job, user or tenant state.
- Execution-scoped data must use explicit scoped services.
- Reset participants may be declared during registration.
- Reset runs between executions, not as module shutdown.
- Detailed scope ownership, reset ordering and leak detection belong to RFC 0005.
- A component that cannot reset safely must not be presented as persistent-worker safe.
- Static mutable request state is forbidden.
- Boot must not capture the first request or tenant context.

## 28. Bridge And Host Lifecycle

In embedded mode:

- The host framework owns the top-level process and application lifecycle.
- Evolve Bridge invokes the selected Evolve lifecycle explicitly.
- A host application must not accidentally boot the same Evolve component twice.
- Host service translation occurs through Bridge adapters.
- Laravel types remain in Laravel-specific adapters.
- Symfony types remain in Symfony-specific adapters.
- Generic module and plugin contracts must not depend on host-framework types.
- Host termination should trigger Evolve shutdown where applicable.
- Detailed Bridge contracts belong to RFC 0006.

## 29. Runtime Adapters

- Core lifecycle behaviour remains runtime-neutral.
- Runtime adapters connect process signals, workers and execution loops to lifecycle operations.
- Runtime SDK types remain outside generic lifecycle contracts.
- A runtime adapter must not alter module ordering.
- Runtime adapters must not silently skip reset or shutdown.
- Persistent-runtime readiness and failure handling must use the accepted lifecycle state.
- FrankenPHP and RoadRunner implementation remains deferred under RFC 0001 unless changed by a later RFC.

## 30. Instrumentation

Lifecycle operations should expose generic instrumentation hooks for:

- Discovery duration.
- Validation duration.
- Dependency graph construction.
- Registration start, completion and failure.
- Boot start, completion and failure.
- Ready transition.
- Shutdown start, completion and failure.
- Component identifiers and types.
- Failure phase.

Rules:

- Core must not depend on Insight.
- Core must not depend on Observe.
- Instrumentation hooks must not expose secrets.
- Instrumentation failure must not normally break lifecycle operation.
- Exact events, spans and metrics belong to RFC 0007.
- Instrumentation must not change lifecycle ordering.

## 31. Error Policy

Lifecycle errors must:

- Identify the lifecycle phase.
- Identify the component where applicable.
- Preserve the original cause.
- Avoid exposing secrets.
- Distinguish validation, dependency, registration, boot and shutdown failures.
- Report dependency cycles clearly.
- Report duplicate identifiers clearly.
- Report missing required capabilities clearly.
- Preserve the primary startup failure when cleanup also fails.

Rules:

- Enabled component failures must not be silently ignored.
- Errors must not be converted into generic success states.
- Production error output may be redacted while logs retain safe diagnostic context.
- Exact exception classes are deferred to implementation.

## 32. Hot Reload And Dynamic Unloading

For the initial lifecycle:

- Modules and plugins are fixed after application boot.
- Runtime enabling is unsupported.
- Runtime disabling is unsupported.
- Dynamic unloading is unsupported.
- Rebuilding the active graph requires application restart or controlled rebuild.
- Shutdown is not hot unload.
- Reset is not hot unload.
- Development tooling may restart the process rather than mutate a live graph.
- A future hot-reload design requires a separate RFC.

## 33. Testing Requirements

Future implementation tests must cover at minimum:

- Successful descriptor discovery.
- No code execution during descriptor parsing.
- Duplicate identifiers.
- Invalid descriptors.
- Missing required dependencies.
- Missing required capabilities.
- Optional dependency absent.
- Optional dependency present.
- Version mismatch.
- Declared conflict.
- Dependency cycle with reported chain.
- Deterministic ordering.
- Dependencies before dependents.
- Stable tie-breaking for unrelated components.
- Disabled component exclusion.
- Disabled required dependency failure.
- Registration order.
- Boot order.
- Service-definition freeze before boot.
- No boot after registration failure.
- Reverse shutdown order.
- Boot failure cleanup.
- Shutdown continuing after one failure.
- Original failure preservation.
- No duplicate lifecycle execution.
- No application readiness after partial failure.
- Host adapter avoiding duplicate boot.
- Application-lifetime objects not receiving execution state.

RFC 0004 itself has documentation-policy tests but does not implement these runtime tests.

## 34. Architecture Enforcement Direction

Future tooling should enforce:

- Descriptor schema validation.
- Duplicate identifier checks.
- Dependency cycle checks.
- Deterministic graph ordering.
- Package-boundary rules.
- No Core dependency on optional packages.
- No production dependency on Testing.
- No host-framework types in generic contracts.
- No service resolution during registration where statically detectable.
- Public API and lifecycle contract documentation.
- Lifecycle state-transition tests.

Architecture tooling is not added in this task.

## 35. Explicit Non-Goals

- This RFC does not create module or plugin interfaces.
- It does not create descriptors or manifests.
- It does not choose a descriptor serialization format.
- It does not implement discovery.
- It does not implement a container.
- It does not implement a kernel.
- It does not create a service provider system.
- It does not create routes, middleware or event dispatching.
- It does not implement reset behaviour.
- It does not implement Bridge adapters.
- It does not implement Runtime adapters.
- It does not implement Insight or Observe.
- It does not sandbox plugins.
- It does not support untrusted in-process plugins.
- It does not support hot reload.
- It does not support runtime enablement or disablement.
- It does not define tenant-specific module activation.
- It does not require every module or plugin to be separately packaged.
- It does not allow arbitrary priority to hide dependencies.
- It does not modify Composer metadata.
- It does not begin RFC 0005 implementation.

## 36. Consequences And Tradeoffs

### Positive

- Deterministic startup.
- Earlier compatibility failures.
- Clear module/plugin distinction.
- Explicit dependencies.
- Better persistent-runtime preparation.
- Predictable cleanup.
- Easier architecture testing.
- Reduced global-state usage.
- Better host-framework integration.
- Optional observability without Core coupling.

### Negative

- More descriptor and validation work.
- Explicit dependency declarations add authoring overhead.
- Strict startup failures may expose previously hidden integration problems.
- No hot reload requires process restarts.
- Dependency graphs and capability selection add complexity.
- Restricted registration APIs require deliberate extension design.
- Plugin trust still requires operational review.
- Reverse-order cleanup requires lifecycle bookkeeping.

## 37. Alternatives Considered

### Execute Module Entry Points During Discovery

Rejected because discovery must be safe, deterministic and side-effect-free.

### Use Recursive Filesystem Scanning By Default

Rejected because it is slow, implicit and dependent on filesystem layout.

### Treat Modules And Plugins As The Same Concept

Rejected because business capability ownership and framework extension ownership differ.

### Use One `boot()` Method For Registration And Initialization

Rejected because structural declaration and runtime initialization require different permissions and failure handling.

### Give Entry Points The Full Mutable Container

Rejected because it encourages service location, hidden dependencies and runtime mutation.

### Resolve Ordering Using Numeric Priority

Rejected as the foundational mechanism because priority hides semantic dependency relationships.

### Ignore Failed Optional Plugins

Rejected when a plugin is explicitly enabled. Optional installation is different from silently ignoring an enabled component failure.

### Permit Dependency Cycles Through Lazy Resolution

Rejected because lazy service access does not remove the architectural cycle.

### Support Hot Unloading In 2.0

Rejected because safe unloading requires stronger resource, state and dependency semantics than the initial lifecycle provides.

### Treat Plugin Validation As Sandboxing

Rejected because in-process PHP remains trusted application code.

## 38. Governance

- RFC 0004 is authoritative for module and plugin lifecycle behaviour.
- RFC 0001 remains authoritative for product direction.
- RFC 0002 remains authoritative for package and public API boundaries.
- RFC 0003 remains authoritative for compatibility and release policy.
- RFC 0005 will define execution scope and reset details.
- RFC 0006 will define Bridge integration details.
- RFC 0007 will define lifecycle telemetry details.
- Concrete interfaces must implement this lifecycle rather than redefine it.
- Material reversals require a superseding RFC.
- Exact API names may be refined during implementation while preserving accepted behaviour and ordering.
- Implemented status requires repository evidence and tests.
