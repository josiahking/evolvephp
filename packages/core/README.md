# EvolvePHP Core

Application kernel and runtime-neutral orchestration for EvolvePHP 2.

## Runtime Foundation

Phase 3.1 introduced `Evolve\Core\ApplicationKernel` as the initial lifecycle implementation for the public `ApplicationLifecycle` contract. Phases 3.2 through 3.5 extended the runtime foundation with boot-time configuration validation, service-registry freezing, explicit execution scopes and, in Phase 3.5, runtime-neutral execution orchestration.

The current lifecycle remains intentionally minimal:

- a new kernel may boot once
- booting runs configured validators before readiness
- a booted kernel may shut down once
- invalid lifecycle transitions fail through the public lifecycle exception catch boundary
- configuration validation failures fail through the public configuration exception catch boundary
- a failed kernel instance is terminal; construct a new kernel to retry corrected startup

`Evolve\Core\Configuration\ArrayConfiguration` is an immutable array-backed configuration implementation created from already-materialized application values. It stores only scalar, null and recursive-array data, supports dot-path lookup through associative maps, treats missing values and explicit null values differently, and rejects objects, resources, malformed keys, ambiguous array maps and list-index path traversal.

`Evolve\Core\Container\ServiceRegistry` is the restricted bootstrap registration API for explicit service definitions. Calling `freeze()` is explicit and idempotent, returns a PSR-11 `Psr\Container\ContainerInterface`, prevents later registration and does not construct service instances. `createExecutionScope()` is legal only after successful explicit freeze and does not implicitly freeze the registry.

The frozen root resolver is read-only: services are consumed through `has()` and `get()`, with application-lifetime services cached after the first successful construction and transient services created on every read. Execution definitions are valid in the frozen graph. Root `has()` reports them, but root `get()` rejects them because they require an explicit `ExecutionScope`.

`Evolve\Core\Execution\ExecutionScope` is a PSR-11 read-only resolver plus two lifecycle/reset methods: `registerResetParticipant()` and `close()`. Application values are shared from the root resolver across scopes, Execution values are lazy and cached once per scope, and Transient values remain uncached. Application factories always receive the root resolver, Execution factories receive the active scope resolver, and Transient factories receive the resolver used for that specific read; this prevents Application-to-Execution captive dependencies while allowing scoped Execution and Transient collaboration.

Reset participation is explicit through the public Contracts `ResetParticipant` interface. A scope resets registered participants in reverse successful registration order, continues after participant failures, reports aggregate close failures through `ExecutionResetFailed::failures()`, closes terminally and idempotently, and releases execution-local caches, resolving state and reset participant references during close. Reset is deterministic execution-state cleanup, not automatic disposal of arbitrary services.

`Evolve\Core\Execution\ExecutionOrchestrator` coordinates one runtime-neutral execution from identifier generation through explicit context creation, operation invocation, scope close and terminal outcome creation. The operation receives an immutable `ExecutionContext` and the explicit `ExecutionScope`; Core does not create an ambient current execution. `ExecutionOutcome` preserves the primary handler result or throwable separately from cleanup/reset failure and exposes an explicit `ProcessReuseDecision`. Clean handler failure remains reusable, while cleanup/reset failure requires quarantine and causes that orchestrator instance to refuse later work.

Core also provides optional generic execution-lifecycle observation hooks around the currently implemented orchestration boundary. A caller may pass an `Evolve\Core\Instrumentation\ObservationSink` to receive immutable safe observations for execution start, handler completion, scope close, quarantine requirement and execution completion. Observation failure is isolated: sink throwables are translated into safe `InstrumentationFailure` values on `ExecutionOutcome` and do not replace the primary handler result or throwable, do not replace cleanup failure and do not alter the reusable/quarantine decision.

Core now includes a minimal runtime-neutral command foundation under `Evolve\Core\Console`. A `Command` exposes only a deterministic name, description and `execute(CommandInput $input, CommandOutput $output): CommandResult`; command dependencies are ordinary constructor-injected dependencies owned by the command implementation. `CommandInput` stores raw ordered string tokens supplied after command selection and does not read `argv`, superglobals or standard input. `CommandOutput` is a tiny runtime-neutral write boundary for normal and error messages; Core provides no stream, terminal, ANSI, TTY, progress or prompt implementation. `CommandResult` carries only the non-negative exit status, where `0` is command success and non-zero is a command-reported status rather than a framework throwable.

`CommandRegistry` is constructed from an immutable bootstrap list of commands, preserves insertion order, performs exact case-sensitive lookup, rejects invalid or duplicate names and throws `CommandNotFound` for unknown commands. `CommandRunner` resolves a command before execution starts and delegates successful resolution to `ExecutionOrchestrator` as an `ExecutionKind::CliCommand` execution, so commands reuse the existing execution identifier, execution context, execution scope, cleanup/reset, instrumentation and reuse/quarantine semantics.

The generic instrumentation foundation is deliberately not Evolve Insight, Evolve Observe or OpenTelemetry. Core does not provide tracing, metrics, logs, spans, propagation, storage, exporters, asynchronous telemetry processing, HTTP instrumentation, module/plugin instrumentation, Bridge instrumentation or runtime-adapter instrumentation in this slice.

Service identifiers follow PSR-11 opaque-string semantics: the empty string is invalid, while other strings are case-sensitive and not trimmed or normalized. Circular dependencies, unknown services, root Execution access and ordinary factory failures are deterministic and catchable through PSR-11 exception interfaces where they occur during service resolution; factory throwables are preserved as previous exceptions when wrapped.

Core now contains the Phase 5.3B Core-owned graph validation and deterministic resolution foundation under `Evolve\Core\Component`. `ComponentGraphResolver` consumes the public experimental Contracts `ComponentGraphDeclaration` model and produces an immutable experimental `ResolvedComponentGraph` without importing Module or Plugin source types. It validates duplicate active component identifiers, missing required dependencies, active one-sided conflicts, required capability providers, provider ambiguity and dependency cycles before later registration or boot work begins.

Required component dependencies must be active and create dependency-first ordering edges. Optional component dependencies are ignored when absent, but when present they create the same dependency-first edge and participate in cycle detection. Conflicts are validation-only and do not create ordering edges. Required capabilities are resolved from active providers: `ExactlyOne` requires one provider or a valid consumer-scoped `CapabilityProviderSelection`, while `OneOrMore` requires all active providers and does not accept provider selection. Self-provided capabilities can satisfy a requirement without creating a self edge. Effective edges are deduplicated, so the same prerequisite-to-dependent relationship from multiple declaration reasons is counted once.

The resolver emits deterministic dependency-first ordering using lexical `ComponentIdentifier` tie-breaking, independent of declaration or selection input order. Cycle failures expose a canonical detected dependency chain through `ComponentDependencyCycle`, rotated to the lexically smallest identifier and closed by repeating the start identifier. These APIs are public experimental and pre-beta; they are graph validation/resolution only.

Core now contains the Phase 5.4 restricted component service-definition registration foundation under `Evolve\Core\Component\Registration`. `ComponentRegistrationCoordinator` consumes `ResolvedComponentGraph::orderedDeclarations()` as the sole ordering authority and binds each callback to the exact resolved `ComponentGraphDeclaration` object before any callback runs. The restricted registrar implements the public experimental Contracts `ServiceDefinitionRegistrar` but exposes only contribution-only methods for Application, Execution and Transient definitions.

Definitions are staged with existing registry identifiers reserved before callbacks, so duplicates, pre-existing service collisions and cross-component conflicts fail deterministically against the active contributor and publish nothing from the staged batch. After all callbacks succeed, Core preflights the complete staged batch against the live `ServiceRegistry` and publishes atomically. Registration invokes no service factories, does not resolve services, does not construct services and does not freeze the registry. The failed coordinator is terminal, retained registrar references become inert after callback completion and `ApplicationKernel` integration remains deferred to Phase 5.5.

This slice provides no shell executable, runtime CLI adapter, argument or option parsing, stdout/stderr stream integration, Doctor, generators or developer commands; Doctor, generators and developer commands remain deferred. It also does not provide environment or dotenv loading, configuration files, autowiring, aliases, service tags, decorators, service-locator globals, HTTP handling, queue or scheduled-job adapters, retry policy, process termination or recycling, module/plugin runtime, telemetry products or integrations, streaming or persistent-worker concurrency guarantees.

Phase 5.4 does not implement `ApplicationKernel` startup integration, Module/Plugin entry-point interfaces, boot, ready, shutdown, discovery, enablement, component instantiation, component versions, dependency version ranges, Composer semantic-version constraint evaluation or Module/Plugin runtime managers. Entry-point lifecycle and boot/ready/shutdown lifecycle orchestration remain deferred to Phase 5.5, and discovery/enablement remains deferred to Phase 5.6.

Core now depends on `evolvephp/contracts` and `psr/container`. The concrete lifecycle implementation, service definition model, frozen container implementation and internal state enum are not an invitation for consumers to depend on internal runtime classes.

## Package

`evolvephp/core`

## Requirements

PHP `^8.4`

## Dependencies

- `evolvephp/contracts`
- `psr/container`

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Installation

Independent Composer installation guidance will be added when package publication begins.

## Licence

BSD-3-Clause. See `LICENSE.md`.
