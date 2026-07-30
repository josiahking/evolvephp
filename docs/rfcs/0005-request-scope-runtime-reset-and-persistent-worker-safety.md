# RFC 0005: Request Scope, Runtime Reset and Persistent-Worker Safety

- Status: Accepted
- Authors: Josiah Chinedu Gerald
- Created: 2026-07-28
- Target release: EvolvePHP 2.0
- Decision type: Execution scope, reset and runtime-safety architecture
- Depends on: RFC 0001, RFC 0002, RFC 0003, RFC 0004
- Supersedes: None
- Superseded by: None

## 1. Summary

RFC 0005 defines how one booted EvolvePHP application safely handles one or more independent executions.

```text
application boot
    |
    +-- execution 1: open -> handle -> close -> reset
    |
    +-- execution 2: open -> handle -> close -> reset
    |
    +-- execution 3: open -> handle -> close -> reset
    |
application shutdown
```

An execution may be an HTTP request, queue message, scheduled job, CLI command or another explicitly supported unit of work. Application boot and shutdown are governed by RFC 0004. Execution scope exists between application boot and shutdown.

Persistent-process reuse is allowed only after successful cleanup and reset. Reset is a safety boundary, not a substitute for correct service lifetimes.

This RFC defines future policy. It does not claim that scope management, reset infrastructure or persistent-runtime support has already been implemented.

## 2. Goals

- Isolation between executions.
- No cross-request user leakage.
- No cross-tenant leakage.
- No stale authentication context.
- No stale locale or timezone context.
- No stale transaction state.
- No stale telemetry context.
- Deterministic cleanup.
- Fail-closed worker reuse.
- Compatibility with traditional one-request-per-process execution.
- Future compatibility with persistent workers.
- Runtime-neutral contracts.
- Explicit Bridge responsibilities.
- Testable safety claims.
- No hidden ambient mutable state.
- No Core dependency on Runtime, Insight or Observe.

## 3. Non-Goals Summary

This RFC does not:

- Implement persistent workers.
- Promise FrankenPHP support.
- Promise RoadRunner support.
- Define multitenancy.
- Define asynchronous concurrency implementation.
- Implement a dependency-injection container.
- Implement transactions.
- Implement OpenTelemetry.
- Implement queue acknowledgement protocols.
- Guarantee third-party code is reset-safe.

## 4. Terminology

### Application Lifetime

The period from successful application boot until application shutdown.

### Execution

One isolated unit of application work.

Examples:

```text
HTTP request
queue message
scheduled job
CLI command
explicit worker task
```

### Execution Scope

The lifetime boundary containing state owned by one execution.

### Application-Scoped Service

A service that may live for the full application lifetime.

### Execution-Scoped Service

A service created for and owned by exactly one execution.

### Transient Service

A service created for a single resolution or operation and not cached by the scope container.

### Ambient Context

Data implicitly available to code without being passed through explicit dependencies.

Examples include current user, tenant, locale, trace, request or transaction.

### Reset Participant

An application-lifetime object that holds reusable mutable infrastructure state and declares deterministic cleanup between executions.

### Scope Closure

The process of preventing new use of an execution scope and disposing of its resources.

### Worker Reuse

Allowing the same booted process to accept another execution.

### Quarantine

Marking a process as unsafe for further work after uncertain or failed cleanup.

### State Leak

Data or behaviour from one execution becoming observable in a later execution.

## 5. Scope Hierarchy

EvolvePHP 2 adopts three foundational lifetimes:

```text
application
execution
transient
```

Rules:

- Application scope is the longest-lived framework scope.
- Execution scope is created separately for every unit of work.
- Transient instances are not shared unless an owning service retains them deliberately.
- Execution-scoped instances must not survive execution closure.
- An execution scope must have one owning runtime or Bridge adapter.
- Nested scopes may be introduced later only through explicit contracts.
- Tenant scope is not a foundational lifetime in EvolvePHP 2.0.
- User scope is not a process-lifetime container scope.
- HTTP request scope is one form of execution scope, not the definition of all execution scopes.

## 6. Lifetime Dependency Rules

Allowed dependency directions:

```text
execution-scoped -> application-scoped
transient -> application-scoped
transient -> execution-scoped when resolved inside an execution
```

Forbidden dependency directions:

```text
application-scoped -> execution-scoped instance
application-scoped -> current request object
application-scoped -> current user instance
application-scoped -> current tenant instance
application-scoped -> execution transaction
```

Rules:

- An application-scoped service may depend on a scope-neutral accessor contract only when that accessor fails outside an active scope and does not retain execution state.
- Direct constructor injection of an execution-scoped service into an application singleton is forbidden.
- Scoped proxies, providers or accessors must not hide lifetime mistakes.
- Static service caches must obey the same lifetime rules.
- The container must detect invalid lifetime dependencies where practical.
- Exact container mechanics are deferred.

## 7. Execution Kinds

### HTTP Request

Begins when an adapter accepts a request for EvolvePHP handling. It ends after response handling and required cleanup complete.

### Queue Message

Begins when a worker assigns one message to EvolvePHP handling. It ends after handler outcome, cleanup and acknowledgement decision complete.

### Scheduled Job

Begins when a scheduler invokes one scheduled unit of work. It ends after result recording and cleanup complete.

### CLI Command

Begins when one command invocation enters EvolvePHP execution handling. It ends after the exit result and cleanup complete.

Rules:

- Execution kind must be known.
- Each execution gets an isolated context.
- Runtime-specific payload types remain in adapter packages.
- Generic Core contracts must not import FrankenPHP, RoadRunner, Laravel, Symfony or queue-vendor types.
- Long-running streaming or interactive executions require explicit future contracts.

## 8. Execution Identifier

- Every execution receives one unique identifier.
- The identifier is created before application code handles the execution.
- It remains stable for that execution.
- It must not be reused for a later execution.
- It may be included in safe logs, diagnostics and telemetry.
- It must not contain secrets or raw authentication tokens.
- The execution identifier is not automatically the OpenTelemetry trace ID.
- Runtime adapters may preserve an upstream correlation identifier separately.
- Identifier generation must not depend on user input without validation.

Exact identifier format is deferred.

## 9. Execution Lifecycle

EvolvePHP adopts this execution lifecycle:

```text
1. Confirm application ready
2. Accept one execution
3. Create execution identifier
4. Create isolated execution scope
5. Populate validated execution context
6. Handle application work
7. Capture the primary outcome
8. Run execution termination hooks
9. Finish active execution telemetry
10. Detach active trace, span and propagation context
11. Close execution-scoped resources
12. Reset reusable application-lifetime participants
13. Clear any remaining ambient execution context
14. Perform bounded export or flush using detached immutable telemetry data
15. Decide whether the process is safe for reuse
16. Return, acknowledge, reject or report the outcome
```

Rules:

- Runtime adapters may need to adapt ordering around response transmission or queue acknowledgement.
- Cleanup and reset must always run through a `finally`-equivalent path.
- The original application outcome remains identifiable after cleanup.
- Termination hooks may still generate telemetry before active telemetry is finalized.
- Post-closure telemetry export or flush must operate only on detached data and must not reactivate the closed execution context.
- A new execution must not start in the same worker until the previous execution reaches a terminal cleanup result, unless explicit concurrency isolation is implemented and proven.

## 10. Execution State Machine

Conceptual states:

```text
created
active
completing
resetting
completed
failed
quarantined
```

Rules:

- Work may only run in `active`.
- Scope resolution after closure is an error.
- Reset runs only after work handling ends.
- Reopening a closed execution scope is forbidden.
- A completed identifier cannot be reused.
- Quarantined workers must not accept more work.
- Exact classes and transition APIs are deferred.

## 11. Execution Context

The execution context may expose validated, scope-owned information such as:

- Execution identifier.
- Execution kind.
- Start time.
- Deadline or cancellation signal.
- Correlation identifier.
- Authentication principal.
- Authorization context.
- Tenant identifier when the application provides one.
- Locale.
- Timezone.
- Trace context.
- Request or message metadata through runtime-neutral abstractions.

Rules:

- Context is immutable or controlled through explicit state transitions.
- Context must not expose secrets by default.
- Context must not be placed in a global mutable array.
- Context must not be retained by application singletons.
- A missing optional context value must remain explicitly absent.
- Context from one execution must never become the default for the next.
- Application code should receive context through explicit contracts.

## 12. Authentication And Authorization Isolation

- Authentication state belongs to execution scope.
- The current principal must be cleared after every execution.
- Anonymous execution must not inherit a previous authenticated principal.
- Authorization caches containing principal-dependent decisions belong to execution scope unless safely keyed and bounded.
- Impersonation state must be execution-scoped.
- Failed authentication must not leave partial identity state.
- Application singletons must not retain current-user objects.
- Reset tests must alternate authenticated and anonymous executions.

## 13. Tenant Isolation Boundary

- RFC 0005 does not define EvolvePHP multitenancy.
- Tenant context belongs to execution scope when supplied by an application.
- A later execution without tenant context must not inherit one.
- Tenant-aware connection selection, caches, configuration overlays and authorization must reset safely.
- Application singletons must not retain a current tenant.
- Static current-tenant helpers are forbidden.
- Persistent-worker safety tests must alternate tenant identifiers where tenant support exists.
- Tenant identity must not be inferred from stale prior execution state.

## 14. Locale And Timezone Isolation

- Locale and timezone selections derived from an execution belong to execution scope.
- Process-wide changes such as `setlocale()` or `date_default_timezone_set()` require controlled restoration.
- Application code should prefer explicit locale and timezone dependencies over process-global mutation.
- Any unavoidable global mutation requires a reset participant.
- A later execution must observe configured application defaults, not the previous execution's values.
- Tests must alternate locale and timezone settings.
- Formatting caches influenced by locale must not leak across executions.

## 15. Static And Global State Policy

- Mutable request-specific static properties are forbidden.
- Mutable request-specific global variables are forbidden.
- Superglobals must be adapted at the runtime boundary.
- Application code must not read `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES`, `$_SERVER` or `$_SESSION` directly as the execution-context mechanism.
- Process-wide registries must not hold current request, user, tenant, locale, transaction or trace state.
- Function-level static caches must be reviewed for execution-sensitive inputs.
- Immutable constants and immutable metadata caches are permitted.
- Application-lifetime caches are permitted only when entries are not execution-secret, are safely keyed and have bounded lifecycle behaviour.
- Resetting globals after every request is not an excuse to design new ambient global APIs.

## 16. Superglobal Adaptation

- Runtime or Bridge adapters may read PHP superglobals at the outer boundary where required.
- They must translate input into explicit request or execution abstractions.
- Core and application modules should consume those abstractions.
- Direct writes to response headers, cookies or output buffers must be owned by adapters.
- Superglobal snapshots must not be retained after execution closure.
- Session state requires an explicit integration contract.
- Exact HTTP abstraction design is outside RFC 0005.

## 17. Execution-Scoped Service Ownership

- The execution scope owns every execution-scoped service it creates.
- Scope closure disposes owned services deterministically.
- A service must not be used after its owning scope closes.
- An execution-scoped service must not register process-lifetime callbacks without a cleanup strategy.
- Lazy execution-scoped services are created only within an active execution.
- Disposal must include services created during error handling.
- Scope-owned resources may include temporary files, streams, cursors, transactions, locks and context handles.
- Exact disposal interfaces are deferred.

## 18. Transient Service Rules

- Transient does not mean process-global.
- A transient object may still hold sensitive execution data.
- The object that retains a transient instance becomes responsible for its lifetime.
- Application singletons must not retain transient instances that contain execution state.
- Disposable transient resources must be owned by a scope or explicit resource manager.
- Transient resolution must not bypass lifetime validation.

## 19. Reset Participants

Application-lifetime services that retain reusable mutable infrastructure state may register as reset participants.

Examples may include:

- Database connection managers.
- Unit-of-work managers.
- Event dispatchers with temporary listeners.
- Deferred callback registries.
- In-memory diagnostic collectors.
- Authorization memoization.
- Locale adapters.
- Telemetry context managers.
- Logger context processors.
- Output-buffer managers.
- Session adapters.
- Temporary filesystem managers.

Rules:

- Registration is explicit.
- Core must not discover reset participants through arbitrary object-graph scanning.
- Each participant has one stable identifier.
- Duplicate reset identifiers are fatal.
- Reset participation does not make a fundamentally unsafe design acceptable.
- Components register reset participants during RFC 0004 registration.
- Reset participants must not resolve arbitrary new execution-scoped services while resetting.

## 20. Reset Ordering

Reset ordering is deterministic.

Rules:

- Execution-scoped resources close before process reuse.
- Dependents reset before their dependencies.
- Reset ordering is the reverse of the accepted dependency or initialization order where such an order exists.
- Participants without an ordering relationship use a documented stable tie-breaker.
- Filesystem enumeration order must not determine reset order.
- Arbitrary numeric reset priority is not the foundational mechanism.
- Cleanup of resources created inside one scope should use reverse creation or acquisition order where practical.
- Telemetry finalization must occur after the application work outcome and termination hooks are known.
- Active trace, span and propagation context must detach before execution-scoped resources close.
- Ambient execution context must be cleared before another execution becomes active.
- Post-closure telemetry export or flush must use detached immutable telemetry data only.
- The exact positioning of telemetry APIs, spans, metrics, logs and exporters may be refined by RFC 0007 without weakening isolation.

## 21. Reset Contract Behaviour

A reset participant must:

- Be safe to call after successful execution.
- Be safe to call after failed execution.
- Remove execution-specific state.
- Restore application defaults where applicable.
- Release or roll back incomplete resources.
- Avoid processing unrelated business work.
- Avoid starting a new execution.
- Avoid hiding failures.
- Be bounded and observable.
- Document whether it may be invoked when partially initialized.
- Avoid assuming the execution was HTTP.

Idempotency is preferred where practical, but repeated reset invocation must not be used as the normal state model.

## 22. Primary Execution Outcome

- The handler result or handler exception is the primary execution outcome.
- Cleanup and reset failures are additional failures.
- Cleanup failure must not erase the primary execution exception.
- A successful handler followed by reset failure becomes an unsafe overall execution result for worker reuse.
- Error reporting must preserve:

```text
primary outcome
cleanup failures
reset failures
worker-reuse decision
```

- Secrets must remain redacted.

## 23. Reset Failure Policy

EvolvePHP uses fail-closed reuse:

- A reset failure means the process cannot prove isolation.
- The worker must be quarantined.
- A quarantined worker must not accept another execution.
- Runtime adapters should terminate or recycle the process after safe reporting.
- Logging a reset failure and continuing reuse is forbidden.
- Remaining reset participants should continue best-effort where safe.
- All cleanup failures should be collected.
- The original execution error remains primary when one exists.
- The runtime must expose that cleanup was incomplete.
- One-request-per-process runtimes may still terminate normally, but the failure must be reported.

## 24. Worker Quarantine

Quarantine means:

- Refusal of new work.
- Completion of best-effort safe cleanup.
- Safe diagnostic reporting.
- Process termination or host-requested recycle.
- No return to ready state.

Quarantine triggers include:

- Reset failure.
- Scope-close failure with uncertain isolation.
- Unclosed transaction.
- Unrecoverable output-buffer corruption.
- Unclear current-user or tenant state.
- Fatal runtime corruption.
- Detected context leak.
- Adapter failure that prevents reliable execution finalization.

The exact process-control mechanism belongs to Runtime adapters.

## 25. Database Safety

- Transactions belong to execution scope unless explicitly documented otherwise.
- Open transactions must not survive execution closure.
- Failed executions must roll back uncommitted transactions.
- Successful handler completion does not permit an uncommitted transaction to leak.
- Transaction cleanup failure quarantines the worker.
- Connection-level session changes must be restored or the connection discarded.
- Examples include:

```text
database role changes
schema or search-path changes
session variables
isolation-level changes
temporary tables
advisory locks
```

- ORM identity maps and units of work must clear between executions.
- Database connections may be reused only when returned to a known-safe state.
- Exact database APIs remain outside this RFC.

## 26. Cache And Memoization Safety

- Execution-specific memoization belongs to execution scope.
- Authentication, authorization, tenant and request-derived cache entries must not use incomplete keys.
- Application-lifetime caches must have explicit safe keying.
- Secret values must not enter shared caches unintentionally.
- Local static caches influenced by execution context must be reset or eliminated.
- Cache reset must not flush unrelated shared infrastructure by default.
- Reset behaviour must be bounded and testable.

## 27. Events, Listeners And Deferred Work

- Temporary listeners belong to execution scope.
- Deferred callbacks must not silently survive into the next execution.
- After-response or after-message work must be owned by the current execution or transferred explicitly to a durable queue.
- Event dispatchers must clear execution-specific listener state.
- Unflushed domain events must have explicit handling.
- A failed execution must not cause deferred success callbacks to run later under another execution.
- Process-lifetime listeners registered during application boot remain application scoped.

## 28. Logging Context

- Execution identifiers and safe correlation fields may be added to logging context.
- Current user, tenant or request fields must be removed after each execution.
- Logger processors must not retain stale context.
- Secrets, tokens, passwords and raw session identifiers must not be logged.
- A log emitted outside execution scope must not inherit the previous execution's fields.
- Reset failure reporting should include safe component identifiers and phases.

## 29. Telemetry Context

- Trace and span context belong to execution scope.
- Active trace, span and propagation context must not survive execution-scope closure.
- Termination hooks may still generate telemetry before active telemetry is finalized.
- The active span must not survive execution closure.
- Baggage and propagation context must clear.
- Post-closure export or flush must operate only on detached data.
- Export must not reactivate the closed execution context.
- Export or flush must be bounded.
- Telemetry export must not block worker cleanup indefinitely.
- An exporter failure does not normally corrupt application state.
- Failure to detach or clear active telemetry context prevents safe worker reuse.
- Core remains independent of Observe.
- Insight diagnostic batches must not merge unrelated executions.
- RFC 0007 may refine APIs, spans, metrics, logs and exporter placement without weakening isolation.

## 30. Output Buffers, Streams And Temporary Resources

- Output buffers opened during an execution must close or restore deterministically.
- Temporary streams and files belong to an explicit scope.
- Resource handles must not survive accidentally.
- Response streaming requires a scope that remains active until streaming completes.
- A process must not begin a new execution while a previous execution still owns an active stream unless concurrency isolation is explicitly implemented.
- Temporary-resource cleanup failure may quarantine the worker when isolation cannot be proven.
- Global output-buffer depth should be restored to an accepted baseline.

## 31. Sessions And Cookies

- Session integration belongs to execution scope.
- Session identifiers and session data must not remain as current ambient state.
- Session locks must release.
- Cookie mutations must be represented through response abstractions.
- A later execution must not inherit the previous session.
- Native PHP session integration requires explicit adapter cleanup.
- Exact session APIs are deferred.

## 32. Cancellation And Deadlines

- Execution context may include a cancellation or deadline signal.
- Cancellation state belongs to one execution.
- A cancelled execution must still perform cleanup and reset.
- Cancellation must not skip transaction rollback.
- A later execution must receive a fresh signal.
- Application-lifetime services must not retain a cancelled token as current state.
- Runtime-specific cancellation types remain in adapters.

## 33. Exception And Fatal-Error Boundary

- Normal exceptions must pass through execution cleanup.
- Runtime adapters should establish the strongest practical `finally` boundary.
- Fatal errors that prevent PHP cleanup may require process termination.
- A process affected by unrecoverable fatal state must not be reused.
- Shutdown handlers must not claim cleanup succeeded when execution state is uncertain.
- Out-of-memory and engine-level failures may bypass normal cleanup.
- Persistent-worker supervisors must replace terminated workers.
- Exact PHP-engine recovery behaviour must not be overstated.

## 34. Traditional Request-Per-Process Compatibility

- Traditional PHP-FPM or CGI-style execution naturally discards process state after a request.
- EvolvePHP must still follow explicit scope rules in those environments.
- Correct scope design is required for tests, embedded use and future persistent workers.
- Reset participants may still run for predictable cleanup.
- Process termination must not be treated as the only correctness mechanism.
- Behaviour should remain equivalent across supported runtime styles where practical.

## 35. Persistent-Worker Reuse Requirements

A process may accept another execution only when:

- Application remains in ready state.
- Previous execution handling ended.
- Execution scope closed.
- Transactions resolved.
- Temporary resources released.
- Reset participants completed.
- Ambient context cleared.
- Telemetry context finalized, active context detached and post-closure export limited to detached data.
- No quarantine trigger exists.
- Runtime adapter confirms safe reuse.

Worker reuse is a privilege earned by successful cleanup, not the default assumption.

## 36. Concurrent Executions

- Sequential execution per worker is the safe baseline.
- Concurrent execution requires independent execution scopes.
- Mutable application services used concurrently must be concurrency-safe.
- Ambient static context is incompatible with concurrent isolation.
- One execution must not close another execution's resources.
- Reset for one execution must not clear another active execution.
- Concurrent support requires explicit runtime contracts and stress tests.
- RFC 0005 does not require Core to support concurrent executions in EvolvePHP 2.0 Alpha.
- A runtime adapter must not advertise concurrency beyond proven framework guarantees.

## 37. Runtime Adapter Responsibilities

Runtime adapters must:

- Confirm application readiness.
- Open one isolated execution scope.
- Translate runtime input into framework abstractions.
- Attach cancellation and correlation context.
- Invoke handling.
- Guarantee cleanup paths where the runtime permits.
- Close scope.
- Trigger reset.
- Respect quarantine.
- Avoid new work after unsafe cleanup.
- Connect process signals to application shutdown.
- Report runtime and reset failures.
- Avoid changing Core scope rules.

Runtime-specific SDK types remain inside runtime packages.

## 38. FrankenPHP Direction

- FrankenPHP integration remains a potential `runtime-*` adapter direction.
- Core must not import FrankenPHP APIs.
- FrankenPHP worker reuse must not be claimed until repeated-execution tests pass.
- Runtime configuration must not bypass reset.
- Worker mode and traditional mode must be documented separately when implemented.
- This RFC does not implement or promise a release date for FrankenPHP support.

## 39. RoadRunner Direction

- RoadRunner integration remains a potential `runtime-*` adapter direction.
- Core must not import RoadRunner APIs.
- Request or job workers must invoke the accepted execution lifecycle.
- A RoadRunner worker must be recycled after quarantine.
- RoadRunner support must not be claimed until repeated-execution tests pass.
- This RFC does not implement or promise a release date for RoadRunner support.

## 40. Bridge Responsibilities

In embedded mode:

- The host owns the top-level process lifecycle.
- Evolve Bridge must create an Evolve execution scope for delegated work where required.
- Host request state must be translated explicitly.
- Host authentication, tenant, locale and trace state must not be retained by Evolve application singletons.
- Bridge must not assume host cleanup automatically resets Evolve state.
- Host container scopes must not be confused with Evolve scopes without an accepted mapping.
- Bridge must avoid duplicate scope creation and duplicate reset.
- Detailed Bridge contracts belong to RFC 0006.

## 41. Module And Plugin Responsibilities

Modules and plugins must:

- Respect service lifetime declarations.
- Avoid mutable request-specific static state.
- Avoid retaining current principal or tenant.
- Register reset participants explicitly when necessary.
- Clean temporary listeners and callbacks.
- Avoid hidden process-wide registries.
- Document external resource ownership.
- Provide repeated-execution tests for stateful integrations.
- Avoid claiming persistent-worker safety without evidence.

An enabled plugin failure during reset quarantines the worker.

## 42. Insight Boundary

- Insight may collect diagnostics per execution.
- Each diagnostic batch must have one execution identifier.
- Batches must close at execution completion.
- Collectors must not retain events into the next batch.
- Insight failure must not normally break application handling.
- Insight must not be required for reset correctness.
- Insight-specific storage remains outside Core.
- Exact batch contracts belong to RFC 0007.

## 43. Observe Boundary

- Observe may create one root span or equivalent telemetry unit per execution.
- Context propagation must remain execution-scoped.
- Observe must clear active context before execution-scope closure.
- Exporter delays must be bounded.
- Observe must not be required for Core reset correctness.
- Observe must not depend on Insight.
- Exact OpenTelemetry integration belongs to RFC 0007.

## 44. Error Reporting

Scope and reset errors must identify:

- Execution identifier.
- Execution kind.
- Lifecycle phase.
- Component or participant identifier.
- Primary outcome.
- Cleanup outcome.
- Worker-reuse decision.
- Whether quarantine occurred.

Rules:

- Secrets must be redacted.
- Error reporting must not require Insight or Observe.
- Cleanup errors must not be silently swallowed.
- Error messages must not falsely claim a complete reset.
- Exact exception classes are deferred.

## 45. Persistent-Worker Safety Claims

A package, plugin or adapter must not claim persistent-worker safety merely because:

- It has no known bug.
- It works for one request.
- PHP did not crash.
- The runtime vendor supports workers.
- A reset method exists.
- Composer installation succeeds.

A safety claim requires evidence including:

- Repeated sequential executions in one process.
- Alternating authenticated and anonymous contexts.
- Alternating tenant contexts where applicable.
- Alternating locale and timezone contexts.
- Success followed by failure.
- Failure followed by success.
- Transaction rollback cases.
- Temporary listener cleanup.
- Logging-context cleanup.
- Telemetry-context cleanup.
- Memory-growth observation.
- Reset-failure quarantine.
- Tests on every advertised persistent runtime.

## 46. Memory Safety Direction

- Memory must not grow without an understood bound across repeated equivalent executions.
- Caches require explicit limits or eviction policy.
- Diagnostic collectors must release completed batches.
- Large request payloads must not remain reachable.
- Scope closure should make execution objects collectible.
- Memory leak detection requires repeated-execution tests.
- Exact memory thresholds depend on workload and are not fixed by this RFC.
- Runtime adapters may recycle workers proactively.

## 47. Testing Requirements

Future implementation tests must cover at minimum:

- New execution scope per request.
- New execution identifier per execution.
- Scope closure.
- Resolution failure after closure.
- Application service cannot directly depend on execution service.
- Execution service can depend on application service.
- Authenticated then anonymous execution.
- Anonymous then authenticated execution.
- Tenant A then Tenant B.
- Tenant then no tenant.
- Locale A then Locale B.
- Timezone A then Timezone B.
- Success then success.
- Success then failure.
- Failure then success.
- Failure then failure.
- Open transaction rollback.
- Connection session-state restoration.
- Temporary listener cleanup.
- Deferred callback cleanup.
- Logger context cleanup.
- Trace context cleanup.
- Insight batch separation.
- Reverse deterministic reset ordering.
- Reset participant failure.
- Remaining reset participants continue best-effort.
- Worker quarantine after reset failure.
- No next execution after quarantine.
- Primary error preserved when cleanup fails.
- Scope-owned stream and temporary-file cleanup.
- Output-buffer restoration.
- Memory-growth checks.
- Runtime adapter repeated-execution tests.

RFC 0005 itself adds only documentation-policy tests.

## 48. Architecture Enforcement Direction

Future tooling should enforce:

- Lifetime dependency rules.
- No application singleton dependency on execution services.
- No forbidden Core dependency on Runtime, Insight or Observe.
- Explicit reset-participant registration.
- Duplicate reset-identifier detection.
- Deterministic reset ordering.
- No direct superglobal access outside adapters where statically detectable.
- No host-framework types in generic Core contracts.
- Repeated-execution test suites.
- Runtime-adapter safety matrices.
- Documentation and changelog requirements for persistent-runtime claims.

No tooling is added in this task.

## 49. Security Considerations

- Cross-user state leakage is a security vulnerability.
- Cross-tenant state leakage is a security vulnerability.
- Stale authorization decisions are a security vulnerability.
- Residual secrets in memory or logs require review.
- Plugin reset failures affect process trust.
- Reset must follow failure paths.
- Quarantine is mandatory when isolation cannot be proven.
- Untrusted plugins remain outside the in-process trust model.
- Persistent workers increase the impact of unsafe global state.
- Security-sensitive leak reports follow `SECURITY.md`.

## 50. Explicit Non-Goals

- This RFC does not implement scopes.
- It does not implement a container.
- It does not create reset interfaces.
- It does not implement HTTP handling.
- It does not implement queue workers.
- It does not implement CLI commands.
- It does not implement database transactions.
- It does not implement sessions.
- It does not implement telemetry.
- It does not implement Insight.
- It does not implement Observe.
- It does not implement Bridge.
- It does not implement FrankenPHP.
- It does not implement RoadRunner.
- It does not promise persistent-worker support in the first alpha.
- It does not define multitenancy.
- It does not define tenant-scoped containers.
- It does not require concurrent executions.
- It does not guarantee reset safety for third-party code.
- It does not modify Composer metadata.
- It does not modify PHP requirements.
- It does not begin RFC 0006.

## 51. Consequences And Tradeoffs

### Positive Consequences

- Stronger isolation between users and tenants.
- Explicit service-lifetime ownership.
- Safer future worker support.
- Predictable cleanup.
- Better error reporting.
- Runtime-neutral scope contracts.
- Easier repeated-execution testing.
- Reduced hidden global state.
- Safer Bridge integration.
- Clear quarantine behaviour.
- Honest worker-safety claims.

### Negative Consequences

- More container and lifecycle complexity.
- Reset participants add maintenance burden.
- Fail-closed quarantine reduces availability when cleanup fails.
- Third-party plugins require deeper review.
- Repeated-execution tests take longer.
- Database and telemetry adapters require careful cleanup.
- Strict lifetime rules may reject convenient singleton designs.
- Concurrency requires additional architecture.
- Persistent-runtime support may be delayed until evidence is sufficient.

These costs are accepted and must remain visible.

## 52. Alternatives Considered

### Rely On Process Termination For Cleanup

Rejected as the only architecture because it prevents safe embedded and persistent-worker designs and hides lifetime errors. Traditional request-per-process remains supported.

### Store The Current Request In A Static Property

Rejected because it leaks ambient state, complicates testing and is unsafe under persistent or concurrent execution.

### Reset Every Service Through Reflection

Rejected because ownership and cleanup behaviour must be explicit.

### Ignore Reset Failures And Continue

Rejected because isolation cannot be proven after failed cleanup.

### Reboot The Complete Application After Every Execution

Not required as the foundational model because controlled execution scope and reset permit reuse. Runtime adapters may still recycle workers.

### Make Every Service Execution-Scoped

Rejected because immutable and infrastructure services can safely be application-scoped and recreating everything may be expensive.

### Make Every Service Application-Scoped And Reset It

Rejected because reset is weaker than correct ownership for request-specific state.

### Use Numeric Reset Priority

Rejected as the foundational ordering model because dependency relationships should define cleanup order.

### Treat HTTP Request Scope As The Only Scope

Rejected because queue, CLI and scheduled work require the same isolation model.

### Claim Worker Safety From One Integration Test

Rejected because state leaks require varied repeated-execution sequences to expose.

## 53. Governance

- RFC 0005 is authoritative for execution scope, reset and persistent-worker safety.
- RFC 0001 remains authoritative for product scope.
- RFC 0002 remains authoritative for package and public API boundaries.
- RFC 0003 remains authoritative for compatibility and release policy.
- RFC 0004 remains authoritative for application, module and plugin lifecycle.
- RFC 0006 will refine Bridge ownership and scope translation.
- RFC 0007 will refine diagnostic and telemetry lifecycle details.
- Concrete interfaces must preserve these safety rules.
- Material reversals require a superseding RFC.
- Persistent-worker support requires repository evidence.
- Runtime adapters must not weaken quarantine or isolation rules.
- Implemented status requires code, tests and runtime-specific validation.
