# EvolvePHP 2 Packages

`packages/` contains the initial EvolvePHP 2 modular-monorepo package set.

The packages define Composer package identities, namespace ownership, dependency direction and the first Phase 3 lifecycle, configuration, service-container, execution-scope, runtime-neutral execution orchestration, generic Core instrumentation and minimal Core console foundations for EvolvePHP 2. Complete runtime implementation is not yet present for the framework, and the packages are not yet published.

All package manifests require PHP `^8.4`.

## Package Map

| Package | Namespace | Responsibility |
| --- | --- | --- |
| `evolvephp/contracts` | `Evolve\Contracts\` | Foundational public-contract boundary, including the initial application lifecycle, configuration, reset-participant and exception contracts. |
| `evolvephp/core` | `Evolve\Core\` | Core orchestration boundary, including the initial minimal application lifecycle kernel, array-backed configuration implementation, PSR-11-readable service container foundation, explicit execution scopes, runtime-neutral execution orchestration outcomes, generic execution-lifecycle observation hooks and the minimal runtime-neutral command foundation. |
| `evolvephp/http` | `Evolve\Http\` | HTTP boundary for later request, response, routing and middleware work. |
| `evolvephp/module` | `Evolve\Module\` | Module SDK boundary for later descriptors and lifecycle contracts. |
| `evolvephp/plugin` | `Evolve\Plugin\` | Plugin SDK boundary for later descriptors and lifecycle contracts. |
| `evolvephp/testing` | `Evolve\Testing\` | Testing-support boundary for later developer testing utilities. |

## Dependency Direction

The arrows in dependency diagrams represent dependency direction, not lifecycle invocation.

The package graph follows an inward dependency principle:

- `contracts` is the innermost package.
- `core`, `module` and `plugin` depend inward on `contracts`.
- `http` depends inward on `contracts` and `core`.
- `testing` may depend on the five production packages for development support.

There is no production dependency on Testing.

No optional package families are present here. Insight, Observe, OpenTelemetry, Bridge, Runtime, Deploy and other optional packages remain deferred to later approved work. Runtime adapters are deferred; Core does not contain `runtime-cli`.

## Current Limitations

Contracts and Core now contain the first Phase 3 lifecycle, configuration, service-container, execution-scope and orchestration foundations: a narrow application boot/shutdown contract, public lifecycle and configuration exception catch boundaries, read-only configuration lookup contracts, a small validation contract, an explicit reset-participant contract, an immutable Core array-backed configuration implementation, deterministic boot-time validation before readiness, explicit service-registry freezing before readiness and explicit execution scopes created only after successful freeze.

Configuration values are application-supplied scalar, null or recursive-array data. Dot-path lookup is supported for associative maps, missing values remain distinct from explicit null values, and validator failure makes that kernel instance terminal; construct a new kernel to retry corrected startup.

Core provides a restricted `ServiceRegistry` bootstrap API for explicit service registration, an idempotent freeze boundary, application-lifetime caching, execution-lifetime caching per explicit scope, transient resolution and PSR-11 read-only `has()`/`get()` interoperability. The root resolver reports known Execution definitions through `has()` but refuses to construct them through root `get()`; execution services resolve only through explicit execution scopes. Application factories always receive the root resolver, Execution factories receive the current scope resolver, and Transient factories receive whichever resolver performed the read. This prevents Application services from capturing shorter-lived Execution services while allowing Execution-to-Application, Execution-to-Transient and scoped Transient-to-Execution resolution.

Execution scopes expose explicit per-scope `ResetParticipant` registration, reset participants in reverse successful registration order, aggregate reset failures after all participants have been attempted, close terminally and idempotently, and release execution-local references during close even when reset fails. Reset participation is not automatic disposal and is not discovered by scanning services.

Core now provides a runtime-neutral `ExecutionOrchestrator` foundation for one sequential unit of work. Each orchestration creates a locally generated opaque execution identifier, classifies the execution kind, passes an explicit immutable execution context and execution scope to the operation, captures the primary handler result or throwable, closes the scope, records cleanup/reset failure separately and reports whether the process remains reusable or must be quarantined. Handler failure alone does not imply quarantine; cleanup/reset failure requires fail-closed quarantine.

Core now provides generic execution-lifecycle observation hooks for the implemented orchestration boundary. Observation is optional, observations contain only safe structured execution facts, sink failure is reported separately as instrumentation failure and instrumentation cannot retry, abort, replace results, suppress cleanup or change reuse/quarantine decisions.

Core now provides a minimal Core console foundation for command selection and dispatch. `CommandRunner` resolves a command from an immutable `CommandRegistry` and executes it through `ExecutionOrchestrator` as an `ExecutionKind::CliCommand` execution. `CommandInput` stores raw ordered token data only, `CommandOutput` remains runtime-neutral, and `CommandResult` carries exit-status semantics without turning non-zero statuses into framework throwables.

HTTP, Module, Plugin and Testing runtime source remains intentionally empty in this slice. EvolvePHP 2 does not yet provide HTTP handling, environment or dotenv loading, configuration files, queue or scheduled-job adapters, retry policy, process recycling or termination, module/plugin runtime, runtime CLI adapters, shell executables, argv or shell parsing, stdout/stderr stream implementations, Symfony Console integration, Doctor, generators, developer tooling commands, Insight, Observe, OpenTelemetry, tracing, metrics, logs, telemetry storage/export, persistent-worker concurrency guarantees or production-ready framework runtime behavior. HTTP remains deferred until Phase 4, Module and Plugin runtime remain deferred until Phase 5, and Phase 6 owns developer tooling.

The EvolvePHP 2 Composer workspace resolves and validates these local packages. See [../workspace/README.md](../workspace/README.md) for setup, testing, quality commands, lockfile, static-analysis, coding-standard and architecture-boundary policy.
