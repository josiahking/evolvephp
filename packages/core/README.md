# EvolvePHP Core

Application kernel and runtime-neutral orchestration for EvolvePHP 2.

## Runtime Foundation

`Evolve\Core\ApplicationKernel` is the lifecycle implementation for the public `ApplicationLifecycle` contract. The runtime foundation includes boot-time configuration validation, service-registry freezing, explicit execution scopes and runtime-neutral execution orchestration.

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

Core contains the Core-owned graph validation and deterministic resolution foundation under `Evolve\Core\Component`. `ComponentGraphResolver` consumes the public experimental Contracts `ComponentGraphDeclaration` model and produces an immutable experimental `ResolvedComponentGraph` without importing Module or Plugin source types. It validates duplicate active component identifiers, missing required dependencies, active one-sided conflicts, required capability providers, provider ambiguity and dependency cycles before later registration or boot work begins.

Required component dependencies must be active and create dependency-first ordering edges. Optional component dependencies are ignored when absent, but when present they create the same dependency-first edge and participate in cycle detection. Conflicts are validation-only and do not create ordering edges. Required capabilities are resolved from active providers: `ExactlyOne` requires one provider or a valid consumer-scoped `CapabilityProviderSelection`, while `OneOrMore` requires all active providers and does not accept provider selection. Self-provided capabilities can satisfy a requirement without creating a self edge. Effective edges are deduplicated, so the same prerequisite-to-dependent relationship from multiple declaration reasons is counted once.

The resolver emits deterministic dependency-first ordering using lexical `ComponentIdentifier` tie-breaking, independent of declaration or selection input order. Cycle failures expose a canonical detected dependency chain through `ComponentDependencyCycle`, rotated to the lexically smallest identifier and closed by repeating the start identifier. These APIs are public experimental and pre-beta; they are graph validation/resolution only.

Core contains the restricted component service-definition registration foundation under `Evolve\Core\Component\Registration`. `ComponentRegistrationCoordinator` consumes `ResolvedComponentGraph::orderedDeclarations()` as the sole ordering authority and binds each callback to the exact resolved `ComponentGraphDeclaration` object before any callback runs. The restricted registrar implements the public experimental Contracts `ServiceDefinitionRegistrar` but exposes only contribution-only methods for Application, Execution and Transient definitions.

Definitions are staged with existing registry identifiers reserved before callbacks, so duplicates, pre-existing service collisions and cross-component conflicts fail deterministically against the active contributor and publish nothing from the staged batch. After all callbacks succeed, Core preflights the complete staged batch against the live `ServiceRegistry` and publishes atomically; this is staged atomic publication. Registration invokes no service factories, does not resolve services, does not construct services and does not freeze the registry. The failed coordinator is terminal and retained registrar references become inert after callback completion.

Core owns the component lifecycle orchestration under `Evolve\Core\Component\Lifecycle` without depending on Module or Plugin packages. `ComponentLifecycleCoordinator` consumes a resolved component graph and exact `ComponentGraphDeclaration` object bindings to `ComponentEntryPoint` instances, validates that binding set before side effects, reuses `ComponentRegistrationCoordinator` for registration and then performs the application startup sequence as registration -> freeze -> boot -> ready through `ApplicationKernel`.

Boot receives a frozen PSR-11 resolver through a restricted `ComponentBootContext`; freezing still does not construct services or create execution scope. Boot and ready run dependency-first according to the already resolved graph order. Normal shutdown runs in reverse successful boot order and is best-effort: every booted component is attempted at most once, failures are attributed and a single `ComponentShutdownFailed` reports all failures after the sequence completes.

Startup cleanup follows RFC 0004: if boot fails, the failing component's deferred boot failure cleanup runs LIFO, the failing component does not receive normal shutdown, previously booted components shut down in reverse order and the original boot throwable remains primary through `ComponentStartupFailed::getPrevious()`. If ready fails, every booted component, including the component whose ready callback failed, shuts down in reverse order and the original ready throwable remains primary. The coordinator prevents invalid, duplicate and reentrant lifecycle transitions and no per-execution component lifecycle work is introduced.

The runtime CLI composition helpers `Evolve\Core\Console\Runtime\CliApplication` and `Evolve\Core\Console\Runtime\StreamCommandOutput` are public experimental APIs for explicit caller-owned shell composition. They preserve Core command execution behavior without introducing a service locator, command discovery, global application object or automatic application bootstrapping. The Core console foundation still does not provide a shell executable for general application composition.

This slice provides no argument or option parsing, Doctor generators or developer commands. It also does not provide environment or dotenv loading, configuration files, autowiring, aliases, service tags, decorators, service-locator globals, HTTP handling, queue or scheduled-job adapters, retry policy, process termination or recycling, module/plugin runtime, telemetry products or integrations, streaming or persistent-worker concurrency guarantees.

Lifecycle orchestration does not implement discovery, component versions, dependency version ranges, Composer semantic-version constraint evaluation or Module/Plugin runtime managers. Explicit application-controlled enablement is handled by the component bootstrap boundary while discovery remains deferred.

Core owns the explicit component bootstrap boundary under `Evolve\Core\Component\ComponentBootstrapper`. Applications pass explicit `ComponentDefinition` objects to the bootstrapper and control activation through the `evolve.components.enabled` configuration list. When that configuration path is absent, no components are active. Unknown, duplicated, malformed or non-string enabled identifiers fail configuration validation before any component validation or entry-point creation occurs.

The bootstrapper keeps disabled definitions inert. For enabled definitions, Core validates every enabled definition before creating any entry point, resolves the existing graph from those validated definitions, creates entry points in resolved dependency-first order and hands exact declaration-to-entry-point bindings to the existing lifecycle coordinator. Definition validation and entry-point construction failures preserve the previous throwable and expose the affected `ComponentIdentifier`.

The bootstrap boundary does not provide Composer discovery, package scanning, descriptor file loading, Composer `extra` schema, automatic enablement, component versions, dependency version ranges, SemVer graph decisions, hot reload or per-execution component lifecycle work.

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
## Evolve Doctor Foundation

Core includes the first runtime-neutral Evolve Doctor diagnostic foundation.
Doctor checks implement `Evolve\Core\Doctor\DoctorCheck` and produce immutable
`DoctorFinding` values with stable machine-readable identifiers, a
`DoctorStatus` of `pass`, `warning`, or `fail`, a human-readable message, and an
optional remediation hint.

`DoctorRunner` accepts explicitly supplied checks, preserves their registration
order, rejects duplicate or malformed check identifiers, and verifies that each
returned finding matches the originating check identifier. `DoctorReport`
preserves findings in runner order and treats the report as successful when no
finding has a `fail` status. Warning findings remain diagnostic data and do not
make a report unsuccessful.

Runtime checks currently provided by Core are limited to:

- `Runtime\PhpVersionCheck`, which verifies that an explicitly supplied or
  current PHP runtime version satisfies the minimum PHP version requirement of
  `8.4.0`.
- `Runtime\PhpExtensionCheck`, which checks a caller-supplied ordered list of
  required PHP extensions using an injectable lookup callback for deterministic
  tests.
- `Project\ComposerRequiredExtensionsCheck`, which reads one local
  `composer.json` path, inspects only top-level `require` keys beginning with
  `ext-`, normalizes extension names to lowercase, and checks whether those
  extensions are loaded. It intentionally ignores `require-dev` and does not
  evaluate extension version constraints.

Normal diagnostic problems, such as an unsupported PHP version or missing PHP
extension, are represented as `fail` findings. Project Composer evidence
problems, such as a missing, unreadable, malformed, or structurally invalid
project manifest, are also represented as `fail` findings. Malformed
definitions, such as duplicate check identifiers, invalid diagnostic
identifiers, invalid caller-supplied extension declarations, or malformed
explicitly supplied PHP versions, fail fast with standard exceptions.

Current limitations: this foundation does not provide JSON output, arbitrary
Composer dependency graph compatibility analysis, a Composer semver solver,
extension version-constraint evaluation, environment inspection, route
inspection, writable-path validation, Bridge validation, persistent-worker
certification, Evolve Audit integration, or automatic remediation.
## Evolve Doctor Console Adapter

Core provides `Evolve\Core\Doctor\Console\DoctorCommand`, a runtime-neutral
adapter from an explicitly configured `DoctorRunner` to the existing Core
`Command` abstraction. The command name is `doctor` and its description is
`Run configured Evolve Doctor diagnostic checks.`

The adapter accepts no arguments or options. Empty input runs the configured
Doctor runner and renders findings deterministically in report order as:

```text
[STATUS] identifier: message
```

When a finding includes remediation, the command emits a following normal output
line:

```text
Remediation: <remediation>
```

PASS, WARNING, and FAIL findings are diagnostic output written through the
normal command output channel. FAIL findings produce command exit status `1`.
Warnings alone remain successful and produce exit status `0`. Unsupported
arguments or options produce exit status `2` with the error
`The doctor command does not accept arguments or options.`

Malformed Doctor definitions and programming failures remain throwables. When
the command is dispatched through Core's generic `CommandRunner`, execution
continues to use the existing `ExecutionOrchestrator` lifecycle for CLI command
failures.

Current Doctor command adapter limitations: this layer does not provide argv
parsing, TTY support, ANSI formatting, prompts, command help UI, JSON Doctor
output, arbitrary Composer dependency graph compatibility analysis, a Composer
semver solver, extension version-constraint evaluation, route inspection,
environment inspection, writable-path inspection, create-project, or generators.
## Evolve CLI Entrypoint

Core exposes a Composer binary for the first shell-facing Evolve CLI entrypoint:

```text
vendor/bin/evolve doctor
```

The package-owned `bin/evolve` executable is a thin composition root around the
existing Core console abstractions. It wires `CliApplication` to `CommandRunner`,
registers the existing `DoctorCommand`, and configures the default shell Doctor
runner with:

1. PHP runtime version diagnosis.
2. Current-project Composer runtime extension discovery from `composer.json`
   `require` declarations whose package names begin with `ext-`.

The project manifest is resolved from the process current working directory.
Only top-level `require` is inspected; `require-dev` is intentionally ignored.
Extension names are normalized to lowercase before lookup, and this diagnosis
checks only whether each declared extension is loaded.

Current shell behavior:

- stdout is used for normal command and Doctor diagnostic output.
- stderr is used for shell and usage errors.
- exit `0` means the Doctor report was successful.
- exit `1` means Doctor reported at least one diagnostic failure.
- exit `2` means CLI usage failed, such as a missing command or unsupported
  Doctor argument.
- PASS, WARNING, and FAIL rendering remains owned by `DoctorCommand`.
- Missing or malformed current-project Composer evidence is a Doctor diagnostic
  failure, writes diagnostics to stdout, and exits `1`.
- Shell usage errors remain stderr output and exit `2`.
- Caller-configured PHP extension checks remain available programmatically but
  are not automatically added by the shell entrypoint.

Current limitations: there is no option parser, `--help` or help framework,
JSON output, command listing or completion, arbitrary Composer dependency graph
compatibility analysis, Composer semver solving, extension version-constraint
evaluation, route inspection, environment inspection, writable-path inspection,
create-project support, generators, Bridge or Audit integration, or
interactive, TTY, or ANSI behavior.

Application skeleton CLI composition is separate from Core's package-owned
binary. The EvolvePHP skeleton explicitly composes the public experimental
`CliApplication` and `StreamCommandOutput` APIs with Core command primitives and
HTTP-owned command adapters. Core remains independent of HTTP; application-owned
shells decide which non-Core commands to register.
## Doctor project diagnostics

Core includes caller-configured project diagnostic primitives in addition to the
package-owned shell Doctor checks.

`Project\EnvironmentVariablesCheck` checks an explicitly supplied ordered list
of required environment-variable names for presence only. Empty-string values
count as present, values are never exposed in messages or remediation, and the
check does not load dotenv files, parse `.env.example`, or define which
variables an application requires.

`Project\WritablePathsCheck` checks an explicitly supplied ordered list of local
filesystem paths with writability inspection. It does not create paths, chmod
files, change permissions, perform automatic remediation, or establish default
storage/cache conventions.

These checks are programmatic Doctor primitives and are not automatically wired
into the package-owned shell Doctor. The shell Doctor remains limited to:

1. PHP runtime version
2. current-project Composer-declared extension presence

Route inspection remains deferred.
