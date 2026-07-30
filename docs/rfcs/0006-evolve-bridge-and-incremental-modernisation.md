# RFC 0006: Evolve Bridge and Incremental Modernisation

- Status: Accepted
- Authors: Josiah Chinedu Gerald
- Created: 2026-07-28
- Target release: EvolvePHP 2.0 Beta
- Decision type: Host integration, interoperability and migration architecture
- Depends on: RFC 0001, RFC 0002, RFC 0003, RFC 0004, RFC 0005
- Supersedes: None
- Superseded by: None

## 1. Summary

Evolve Bridge allows existing applications to adopt EvolvePHP 2 capability by capability rather than through a complete replacement.

Conceptual direction:

```text
existing host application
        |
        +-- existing capability
        |
        +-- existing capability
        |
        +-- Evolve Bridge
                |
                +-- EvolvePHP capability
```

Bridge is an integration boundary. It is not an automatic code converter, and it is not a compatibility layer that makes incompatible PHP dependencies safe. Bridge supports explicit delegation of selected capabilities while the host remains functional and migration proceeds in controlled increments.

Same-process and remote modes have different compatibility, trust, lifecycle and failure boundaries. Migration must preserve one clear owner for each capability and data set.

RFC 0006 defines accepted architecture policy. It does not claim that Bridge packages, adapters or protocols already exist.

## 2. Goals

- Incremental adoption.
- Clear host and Evolve ownership.
- Explicit route and capability delegation.
- Runtime-neutral generic contracts.
- Host-specific adapter isolation.
- Compatibility with Laravel and Symfony adapters where implemented.
- Compatibility with PSR-based integration where practical.
- Support for unsupported legacy PHP applications through remote mode.
- Deterministic lifecycle integration.
- Execution-scope isolation.
- Explicit identity and context propagation.
- Clear failure handling.
- Contract versioning.
- Safe rollback planning.
- Testable compatibility claims.
- No Core dependency on Bridge packages.
- No hidden coupling to host-framework internals.
- No requirement to rewrite the complete host application.

## 3. Non-Goals Summary

RFC 0006 does not:

- Implement Bridge.
- Rewrite an existing application.
- Parse or transform legacy source code.
- Guarantee automatic migration.
- Guarantee zero downtime.
- Guarantee zero data migration.
- Provide distributed transactions.
- Make incompatible Composer dependencies coexist.
- Make unsupported PHP versions valid for same-process use.
- Sandbox host or Evolve code.
- Define every Laravel or Symfony integration API.
- Publish a remote protocol schema.
- Implement service discovery.
- Define Evolve Deploy.
- Implement RFC 0007 observability.

## 4. Terminology

### Host Application

The existing application that delegates selected work to EvolvePHP.

### Host Adapter

A package containing integration code for a particular host framework or interoperability standard.

### Evolve Application

The EvolvePHP application or capability receiving delegated work.

### Bridge

The explicit boundary translating between the host and Evolve application.

### Embedded Mode

Same-process integration where the host and Evolve code execute within one PHP process and one compatible dependency graph.

### Remote Mode

Integration where the host communicates with an independently running Evolve application through a versioned network protocol.

### Sidecar Mode

A remote deployment pattern where the Evolve service is deployed near the host but remains a separate process.

### Delegated Capability

A bounded function or route whose execution responsibility has moved to EvolvePHP.

### Capability Owner

The system responsible for the authoritative behaviour and data of a capability.

### Translation Boundary

The adapter layer converting host-specific types and context into Evolve public contracts and converting results back.

### Cutover

The controlled moment at which authoritative traffic or behaviour moves from the host implementation to Evolve.

### Rollback

A planned reversal of cutover that accounts for routing, data and side effects.

### Compatibility Matrix

Documented combinations of host framework, host version, PHP version, Bridge adapter and EvolvePHP version that have been tested.

## 5. Bridge Principles

1. Integration is explicit.
2. Ownership is unambiguous.
3. Translation occurs at the boundary.
4. Host-framework types do not enter generic Core contracts.
5. Evolve Core does not depend on Bridge.
6. Same-process compatibility must be proven through one coherent dependency graph.
7. Remote compatibility must be proven through a versioned protocol.
8. Execution scope must be created exactly once for delegated work.
9. Security context must be validated, not assumed.
10. Failure behaviour must be configured deliberately.
11. Data ownership must be defined before cutover.
12. Migration proceeds one bounded capability at a time.
13. A rollback plan must exist before production cutover.
14. Bridge support claims require integration evidence.

## 6. Integration Modes

Evolve Bridge accepts two foundational modes:

```text
embedded
remote
```

Sidecar is a deployment form of remote mode, not a third execution model.

Applications may use different modes for different capabilities, but a single delegated operation must use one clearly selected mode. Runtime switching between embedded and remote implementations must not happen silently. Mode selection belongs to application configuration. Production mode changes require deployment review.

Bridge adapters must expose which modes they support. A capability must not be delegated simultaneously to two authoritative implementations.

## 7. Embedded Integration

Embedded mode means:

- Host and Evolve run in one PHP process.
- Host and Evolve must support the same PHP runtime.
- Host owns the top-level process lifecycle.
- Bridge invokes Evolve application lifecycle explicitly.
- Host request or job objects are translated into Evolve contracts.
- Evolve results are translated back into host responses or results.
- Host-specific types remain in the host adapter.
- Evolve application shutdown is connected to host shutdown where applicable.

Embedded delegation must satisfy the complete compatibility rules in Section 8, Embedded Compatibility Rules. It must also preserve the lifecycle ownership rules in Section 14, Lifecycle Ownership By Integration Mode, and the execution-scope rules in Section 22, Execution-Scope Translation.

Embedded mode must not store the host's current request in an Evolve singleton, allow both host and Evolve to emit the same response, call `exit` from generic Evolve handling code, or assume host cleanup automatically resets Evolve execution state.

## 8. Embedded Compatibility Rules

Same-process integration requires:

- Overlapping PHP support.
- Host and Evolve support for the same PHP runtime.
- One coherent Composer dependency graph.
- Compatible Composer constraints.
- Compatible PSR package versions where used.
- Compatible host-framework version.
- Compatible Bridge adapter version.
- Compatible EvolvePHP version.
- Successful dependency resolution without ignored constraints.
- Integration tests on the declared combination.

Rules:

- One process must use one coherent Composer dependency graph.
- Multiple incompatible copies of foundational packages are unsupported.
- Embedded mode must not load incompatible versions of the same dependency through separate vendor directories.
- Embedded mode must not use `--ignore-platform-reqs` to force compatibility.
- Embedded mode must not use class aliases to disguise package conflicts.
- A package being installable does not prove lifecycle compatibility.
- An adapter must document its tested host-version range.
- Same-process mode is unavailable when the host cannot run on EvolvePHP's supported PHP version.
- Legacy PHP applications below the EvolvePHP baseline must use remote or sidecar mode.
- Compatibility claims must follow RFC 0003 evidence rules.

Documentation tests running on PHP 8.3 do not establish EvolvePHP 2 runtime compatibility.

## 9. Remote And Sidecar Integration

Remote mode means:

- Host and Evolve run in separate processes.
- Each process owns its own Composer dependency graph.
- Each process may use a different PHP version when its own software supports it.
- Communication uses an explicit versioned protocol.
- The Evolve service owns its own application lifecycle.
- The host Bridge client owns invocation and response handling.
- Network failure is possible and must be handled explicitly.
- Identity and correlation context are propagated through validated protocol fields.
- Shared PHP memory, sessions and container services are unavailable.
- Remote mode creates a genuine network security boundary.

Sidecar mode is a remote Evolve process deployed near the host application. It still has an independent process and application lifecycle, separate PHP and Composer environments, versioned remote protocol, independent readiness and health, and independent process recycling.

Same machine, same VM, localhost transport or the same container group does not make sidecar mode same-process. Sidecar deployment does not create automatic shared transactions. Sidecar restarts must not corrupt host state. The host must handle sidecar unavailability according to the resilience rules in Section 31, Remote Resilience And Fallback.

Remote mode must not serialize arbitrary PHP objects, share native PHP session memory, assume an atomic database transaction across processes, expose internal exception traces to clients, trust unsigned identity claims, retry unsafe writes blindly, or treat timeout as proof that no work occurred. Section 28, Remote Protocol Boundary, is the canonical remote protocol policy.

## 10. Mode-Selection Guidance

Embedded mode is suitable when PHP and Composer constraints overlap, low translation latency matters, one deployment lifecycle is acceptable, same-process trust is acceptable, and host and Evolve can share a tested dependency graph.

Remote mode is suitable when PHP versions do not overlap, dependencies conflict, independent deployment is required, stronger process isolation is required, the capability may later become a service, scaling characteristics differ, or the host is too old to embed Evolve safely.

Remote mode is not automatically superior; it adds network, deployment and consistency complexity. Embedded mode is available only when the compatibility requirements in Section 8, Embedded Compatibility Rules, are satisfied.

## 11. Bridge Package Boundaries

RFC 0006 reaffirms the RFC 0002 direction.

Potential first-party package family:

```text
evolvephp/bridge-contracts
evolvephp/bridge-psr
evolvephp/bridge-laravel
evolvephp/bridge-symfony
evolvephp/bridge-remote
```

Namespace direction:

```text
Evolve\Bridge\Contracts\
Evolve\Bridge\Psr\
Evolve\Bridge\Laravel\
Evolve\Bridge\Symfony\
Evolve\Bridge\Remote\
```

### `bridge-contracts`

- Contains stable generic Bridge contracts.
- Generic Bridge contracts must not contain Laravel or Symfony types.
- Generic Bridge contracts contain no Laravel or Symfony types.
- `bridge-contracts` depends on `evolvephp/contracts`.
- `bridge-contracts` must not depend on Core or HTTP implementations.
- Must remain small.
- Must not become a duplicate Core package.

### `bridge-psr`

- Adapts applicable PSR standards.
- Depends on explicit PSR packages.
- Must not make every host framework PSR-native internally.
- Must not force Core to depend on PSR adapters.

### `bridge-laravel`

- Contains Laravel-specific translation.
- May depend on documented Laravel packages and versions.
- Laravel types remain inside this adapter.
- Core must never depend on it.

### `bridge-symfony`

- Contains Symfony-specific translation.
- May depend on documented Symfony components and versions.
- Symfony types remain inside this adapter.
- Core must never depend on it.

### `bridge-remote`

- Contains remote Bridge client/server integration contracts and adapters.
- Must not expose transport-vendor types through generic contracts.
- May support a baseline HTTP protocol direction.
- Must keep authentication and protocol versioning explicit.

Not every conceptual package must ship in the first Bridge release.

## 12. Dependency Direction

RFC 0006 reaffirms RFC 0002's inward dependency direction and refines low-level Bridge edges without reversing RFC 0002.

Accepted package dependency policy:

- `evolvephp/bridge-contracts` depends on `evolvephp/contracts`.
- `evolvephp/bridge-psr` depends on `evolvephp/bridge-contracts`, selected public Evolve packages and selected PSR packages.
- `evolvephp/bridge-laravel` depends on `evolvephp/bridge-contracts`, selected public Evolve packages and supported Laravel packages.
- `evolvephp/bridge-symfony` depends on `evolvephp/bridge-contracts`, selected public Evolve packages and supported Symfony packages.
- `evolvephp/bridge-remote` depends on `evolvephp/bridge-contracts`, selected public Evolve packages and selected transport or interoperability packages.

Rules:

- `bridge-contracts` depends on `evolvephp/contracts`, not Core or HTTP implementations.
- Adapter packages may depend directly on selected public Evolve packages where required.
- Adapter packages do not automatically depend on every Evolve package shown above.
- Core and HTTP never depend on host-specific Bridge adapters.
- Generic Bridge contracts contain no Laravel or Symfony types.
- RFC 0002's inward dependency direction remains authoritative.
- RFC 0006 refines low-level Bridge edges without reversing RFC 0002.

Forbidden:

```text
core -> bridge-*
http -> bridge-*
http -> bridge-laravel
http -> bridge-symfony
contracts -> host framework
bridge-contracts -> core
bridge-contracts -> http
bridge-contracts -> Laravel
bridge-contracts -> Symfony
bridge-laravel -> application internals
bridge-symfony -> application internals
```

Core must never depend on a Bridge adapter. Application-owned integration packages may depend on both an application module contract and a Bridge adapter when needed.

## 13. Bridge Is Not A Module Or Plugin Synonym

A Bridge adapter is an integration adapter category. A Bridge adapter may participate in registration through approved lifecycle contracts, but it does not become an application business module and is not automatically a framework plugin.

Host-specific integration remains isolated regardless of registration mechanics. Bridge does not change module or plugin definitions established by RFC 0004.

## 14. Lifecycle Ownership By Integration Mode

Lifecycle ownership has one owner at each boundary. RFC 0004 remains authoritative for application/module/plugin lifecycle, and RFC 0005 remains authoritative for execution scope, reset and quarantine.

### Embedded Lifecycle

In embedded mode, the host owns the top-level process lifecycle, including process startup, top-level configuration loading, top-level error boundary, host service container, host request acceptance, outer routing decision, process signals, final response emission and process shutdown.

Evolve owns after delegation:

- Evolve application readiness validation.
- Evolve execution-scope creation.
- Evolve request or job handling.
- Evolve module and plugin lifecycle.
- Evolve execution cleanup and reset.
- Evolve result production.

Rules:

- Ownership transitions must be explicit.
- Host and Evolve must not both boot Evolve.
- Evolve application must not boot twice.
- Host and Evolve must not both emit the same response.
- Host shutdown must invoke Evolve shutdown at most once.
- Evolve reset failure must be reported to the host.
- A host must not reuse an unsafe embedded worker after Evolve quarantine.
- Exact integration hooks are adapter-specific.

### Remote Lifecycle

In remote mode, the host owns host process lifecycle, Bridge client lifecycle, invocation timeout, client-side retry policy, and host response or fallback decision.

The Evolve service owns Evolve process lifecycle, Evolve application boot and readiness, Evolve execution scope, Evolve handling, reset and quarantine, and Evolve response production.

Rules:

- The host must not attempt to manage internal Evolve service scopes.
- The Evolve service must not assume host process state.
- A remote response represents a protocol result, not shared memory.
- Service restarts and host retries must respect idempotency policy.

## 15. Route Delegation And Ownership

Every delegatable route must have one authoritative owner.

Possible ownership states:

```text
host
evolve
disabled
```

Supported architectural directions:

- Explicit mount: a host mounts a defined Evolve route group under a configured prefix.
- Explicit route mapping: individual host routes delegate to named Evolve operations.
- Capability gateway: host code calls a typed Bridge capability contract.
- Remote endpoint delegation: host calls a versioned Evolve remote operation.

Rules:

- Route delegation must be explicit.
- Route ownership must be explicit and deterministic.
- Route prefixes or named route mappings must be documented.
- Evolve must not scan and override host routes silently.
- Hidden global route interception is forbidden.
- Host route precedence must be deterministic.
- Ambiguous route ownership is a configuration error.
- Two handlers must not both perform authoritative side effects.
- Delegated routes must identify their Bridge mode.
- Route ownership changes require deployment review.
- Route caches must invalidate when ownership changes.
- Catch-all fallback routing is not the default.
- Delegation must be observable.
- State-changing delegation must define idempotency and failure behaviour.
- Exact route APIs remain deferred.

## 16. Request And Response Translation

Request and response translation occurs at the Bridge boundary.

The translation boundary must map:

- Method or operation.
- Path or capability name.
- Query input.
- Headers through an allowlist.
- Body or payload.
- Uploaded resources through explicit abstractions.
- Cookies where applicable.
- Principal and authorization context.
- Tenant context where supplied.
- Locale and timezone.
- Correlation and trace context.
- Deadline or cancellation.
- Response status.
- Response headers through an allowlist.
- Cookies through explicit policy.
- Response body or result.
- Safe error information.

Rules:

- Host request objects do not enter generic Evolve contracts.
- Host request objects are translated into Evolve contracts.
- Evolve response objects do not leak into unrelated host code.
- Translation must not retain request state beyond execution closure.
- Header and metadata forwarding must use allowlists.
- Hop-by-hop transport headers must not be forwarded blindly.
- Sensitive headers must be redacted from logs.
- Payload limits must be enforceable.
- Exact DTO and interface names are deferred.

## 17. PSR Interoperability

PSR standards should be used when they fit the integration requirement. A PSR adapter may translate PSR-7 requests and responses. Middleware interoperability may use PSR-15 where appropriate. Logger integration may use PSR-3 where appropriate. Container interoperability may use PSR-11 only as a limited resolution contract, not as unrestricted host-container mutation.

PSR compatibility does not eliminate lifecycle, scope or ownership requirements. Host-specific adapters may still be required. Core must not depend on a Bridge adapter merely to support PSR integration.

This RFC does not lock exact package requirements.

## 18. Middleware Boundaries

Possible middleware layers:

```text
host middleware
Bridge translation middleware
Evolve middleware
```

Rules:

- Ordering must be explicit.
- Host middleware runs before delegation unless specifically documented.
- Evolve middleware runs after Evolve execution scope opens.
- Translation middleware must not perform business logic.
- Authentication must state whether it occurs in the host, Evolve or both.
- Authorization-sensitive behaviour must not depend on accidental middleware order.
- Response transformations must have one owner.
- Error middleware must preserve the original failure.
- Middleware priority does not replace component dependency declarations.

## 19. Authentication Propagation

Authentication propagation is allowed only through a defined trust contract and validated assertions.

Embedded mode may translate:

- Validated principal identifier.
- Authentication method.
- Authentication time.
- Roles or claims as untrusted or validated input according to policy.
- Impersonation status.
- Session correlation.

Remote mode requires authenticated service-to-service communication, integrity-protected identity assertions, expiry or freshness checks, audience validation, issuer validation where tokens are used, replay controls where required, and transport encryption.

Rules:

- Raw host session objects must not cross the Bridge.
- Evolve must not trust arbitrary identity headers.
- Principal data belongs to Evolve execution scope.
- Anonymous operations must remain explicitly anonymous.
- Authentication context must clear after execution.
- Exact token format is deferred.

## 20. Authorization Responsibilities

Authentication propagation does not transfer authorization ownership automatically.

- Evolve must authorize Evolve-owned operations.
- Host authorization may control whether delegation is attempted.
- Evolve authorization controls whether the delegated capability executes.
- Host roles must not map silently to Evolve permissions.
- Claim mapping must be explicit and tested.
- Security-critical operations must fail closed.
- Authorization decisions must not be cached across users or tenants incorrectly.
- Remote callers require operation-level authorization.
- Audit records should identify the originating host and principal safely.

## 21. Sessions, Cookies And CSRF

Embedded mode may integrate host sessions through an explicit adapter. It must not expose native session globals to Evolve Core, must define lock and cleanup ownership, must translate cookie mutations through the response boundary, and must define which layer owns CSRF validation.

Remote mode does not share native PHP session memory. It must use explicit identity and state-transfer mechanisms. Host cookies must not be forwarded indiscriminately, remote Evolve cookies are not automatically host cookies, and CSRF protection remains associated with the browser-facing application boundary.

Rules:

- A later execution must not inherit the previous session.
- Session secrets must not be logged.
- Exact session adapter APIs are deferred.

## 22. Execution-Scope Translation

Embedded delegation must:

1. Confirm Evolve application readiness.
2. Create exactly one Evolve execution scope.
3. Populate validated translated context.
4. Invoke one delegated operation.
5. Close the Evolve scope.
6. Reset Evolve participants.
7. Report quarantine to the host.
8. Return the translated result.

Rules:

- Host request scope and Evolve execution scope are related but not identical automatically.
- The adapter must not create duplicate Evolve scopes.
- Host execution-scoped objects must not be stored in Evolve application singletons.
- Evolve scope closure must occur on success and failure.
- Reset failure prevents safe embedded worker reuse.
- RFC 0005 remains authoritative for isolation.

Lifecycle ownership, including duplicate boot prevention, is defined in Section 14, Lifecycle Ownership By Integration Mode.

## 23. Container Integration

- Host and Evolve containers retain separate ownership.
- Bridge may expose selected services through explicit adapter definitions.
- Bridge must not merge two mutable containers into one ungoverned registry.
- Evolve must not mutate undocumented host definitions.
- Host code must not resolve Evolve internal services.
- Evolve code must not resolve arbitrary host internals.
- Shared services require stable contracts and explicit lifetime mapping.
- An application-scoped host service must not receive an Evolve execution-scoped instance.
- A host request-scoped service must not survive Evolve scope closure.
- PSR-11 access does not make every service a public contract.
- Exact container APIs are deferred.

## 24. Capability Mapping And Configuration

Bridge capabilities may represent facilities such as:

```text
authentication
authorization
cache
queue
events
mailer
filesystem
logging
telemetry
database access
```

Rules:

- Capability mapping must be explicit.
- A host capability may satisfy an Evolve requirement only through a documented adapter.
- Capability names are not raw container identifiers.
- Multiple providers require explicit selection.
- Remote mode may not expose every host capability.
- Security-sensitive capabilities require narrower contracts.
- Capability mapping must not bypass package dependencies.
- A missing required capability prevents delegated execution.
- Optional capabilities remain optional.

Bridge configuration must be namespaced and validate mode, host adapter, Evolve application target, route or capability mapping, mount prefix, timeout, retry policy, idempotency requirements, authentication configuration, trusted issuers or service identities, header forwarding policy, payload limits, failure and fallback policy, compatibility requirement, health criticality, and correlation propagation.

Secrets must not appear in descriptors or diagnostics. Invalid Bridge configuration prevents activation. Production systems must not discover and enable Bridge routes silently. Configuration changes affecting ownership require restart or controlled rebuild. Exact configuration syntax is deferred.

## 25. Data Ownership

Before delegating a capability, document:

- Authoritative system.
- Authoritative data store.
- Read ownership.
- Write ownership.
- Identifier ownership.
- Validation ownership.
- Migration state.
- Synchronization mechanism if temporary.
- Rollback implications.
- Retention and deletion responsibilities.

Rules:

- One system must be the authoritative writer for an aggregate at a time.
- Shared reads do not imply shared write ownership.
- The host and Evolve must not independently mutate the same aggregate without an explicit consistency design.
- Data ownership must be clear before route cutover.
- Legacy schema access from Evolve requires an explicit adapter and migration plan.
- Database table proximity does not create a public integration contract.

Cutover, dual-write, source-of-truth and decommissioning sections reference this section rather than redefining authoritative write ownership.

## 26. Database And Transaction Boundaries

Embedded mode may use separate database connections, an explicitly adapted shared connection, a legacy-data access adapter, or a new Evolve-owned schema.

Connection ownership and transaction ownership must be defined. Connection session state must obey RFC 0005. Evolve must not assume a host transaction exists. Host rollback must not silently imply Evolve rollback unless one transaction owner is explicitly established. Sharing a connection does not automatically make cross-capability transactions safe. ORM identity maps must remain isolated.

Remote mode uses process-local connections and cannot share an in-memory transaction.

Transaction policy:

- The default Bridge boundary is not a distributed transaction.
- Embedded operations should have one explicit transaction owner where atomicity is required.
- Remote operations must not assume atomicity across host and Evolve databases.
- Cross-process workflows should use patterns such as idempotent operations, outbox, inbox or deduplication, compensating actions, sagas and reconciliation.
- Exact pattern choice belongs to the capability design.
- Transaction failure must not trigger unsafe duplicate fallback.
- Uncertain outcomes must be surfaced explicitly.
- A remote timeout does not prove rollback.

## 27. Events And Asynchronous Integration

Bridge may translate events through explicit contracts.

Rules:

- Internal host events are not automatically Evolve public events.
- Event payloads require versioned schemas.
- Event ownership must be documented.
- Remote delivery is at-least-once unless proven otherwise.
- Consumers must handle duplicates where delivery can repeat.
- Ordering guarantees must be explicit.
- Failed remote delivery requires retry or dead-letter policy.
- Sensitive event fields must be minimized.
- In-process event dispatch must not hide module dependency cycles.
- Durable events are preferred for cross-process state changes.
- Exact broker implementation is outside RFC 0006.

## 28. Remote Protocol Boundary

Adopted direction:

- Versioned HTTP-based interoperability is the initial baseline for remote Bridge.
- JSON is the initial broadly compatible structured payload direction.
- Transport and serialization details remain implementation decisions subject to testing.
- TLS is required outside explicitly trusted local development environments.
- Protocol endpoints must be explicit.
- Arbitrary remote method invocation is forbidden.
- PHP serialization is forbidden.
- Internal class names must not be protocol contracts.
- Protocol schemas must be language-neutral where practical.
- Streaming and binary transfer require separate explicit designs.

A future remote protocol must be able to represent protocol version, operation or capability identifier, request identifier, correlation identifier, deadline, idempotency key where required, authenticated caller identity, principal assertion where permitted, locale and timezone context, trace propagation, payload, success or error status, safe error code, retryability indication where appropriate, and Evolve execution identifier in the response where safe.

Rules:

- Fields must have defined limits.
- Unknown required fields must fail safely.
- Unknown optional additive fields may be ignored according to version policy.
- Secrets must not be reflected in errors.
- Exact envelope names are deferred.

This RFC does not publish endpoint paths, JSON schemas or a complete protocol schema.

## 29. Protocol Compatibility And Versioning

- Bridge package versions follow RFC 0003.
- Remote protocol has an explicit compatibility version.
- Protocol major changes may be breaking.
- Additive backward-compatible fields may be introduced within a compatible version policy.
- Client and server must negotiate or validate compatibility before executing state-changing work.
- Incompatible versions fail before business side effects.
- Protocol version and Evolve package version are related but not necessarily identical.
- Capability contracts may have their own versions where necessary.
- Deprecated protocol fields require migration guidance.
- Published protocol versions are immutable.
- A compatibility matrix must identify tested client/server combinations.

## 30. Error Model

Bridge errors must distinguish configuration failure, compatibility failure, authentication failure, authorization failure, validation failure, host translation failure, Evolve boot or readiness failure, Evolve execution failure, timeout, cancellation, transport failure, protocol failure, uncertain outcome, reset or quarantine failure, and dependency unavailable.

Rules:

- Internal stack traces must not cross a remote boundary.
- Safe machine-readable error codes are preferred.
- Errors must preserve correlation identifiers.
- Cleanup failures must not erase the primary operation failure.
- Reset failure must indicate that reuse is unsafe.
- The host must not convert every failure into a generic success response.
- Exact exception classes and protocol codes are deferred.

## 31. Remote Resilience And Fallback

Remote resilience follows this sequence:

```text
timeout or transport failure
    -> determine whether outcome is known or uncertain
    -> apply idempotency and retry policy
    -> apply explicit fallback policy
    -> never duplicate uncertain writes
```

Timeout and cancellation policy:

- Every remote operation must support a bounded timeout.
- State-changing operations need clearly documented timeout semantics.
- Deadline propagation should be supported.
- Cancellation belongs to one execution.
- Cancellation does not prove that remote processing stopped.
- Cleanup must still occur after cancellation.
- A remote timeout does not prove that no side effect occurred.
- Host timeout must not cause automatic legacy fallback after an uncertain write.
- Remote Evolve handling should observe cancellation where practical.
- Timeout values must be configurable and bounded.
- Long-running work may require asynchronous job submission rather than one synchronous Bridge call.

Retry and idempotency policy:

- Safe reads may be retried according to explicit policy.
- State-changing operations must not be retried automatically without idempotency protection.
- Idempotency keys must be scoped and validated.
- A repeated idempotency key must return the prior accepted outcome or a defined conflict.
- Idempotency records need retention policy.
- Retry count and backoff must be bounded.
- Authentication and validation failures are not generally retryable.
- Protocol incompatibility is not retryable.
- Reset or quarantine failure is not resolved by repeating the same process invocation.
- Retry storms must be prevented.

Circuit breaking and load protection may use circuit breakers, concurrency limits, rate limits, bulkheads, queueing and backpressure. Protection state must not leak across unrelated configured targets incorrectly. Circuit state must be observable. Open circuits must produce explicit unavailable outcomes. Security-sensitive operations must not fail open. Protection mechanisms must not silently redirect writes to legacy implementations. Exact libraries and algorithms are deferred.

Each delegated capability must document one of:

```text
fail closed
explicit safe fallback
degraded read-only behaviour
host-defined unavailable response
```

Fallback rules:

- Fail closed is the default for writes and security-sensitive operations.
- Fallback must be deliberate and tested.
- Automatic fallback is forbidden after partial or uncertain side effects.
- Legacy fallback must not create duplicate writes.
- Reads may fall back only when data semantics are acceptable.
- Fallback must be observable.
- Fallback must not hide persistent incompatibility.
- A Bridge configuration must not claim resilience merely because failures are swallowed.

## 32. Failure Isolation And Health

### Embedded Failure Isolation

Failure in delegated Evolve work should remain within the delegated operation where possible. Evolve boot failure makes Evolve delegation unavailable. The host may continue serving unrelated host-owned capabilities when explicitly configured. Security-critical delegated routes must fail closed.

Reset failure quarantines the shared process under RFC 0005. The host must not continue reusing a process whose Evolve state is uncertain. Fatal engine errors may terminate the complete process. The adapter must preserve the original failure and safe diagnostics.

### Remote Failure Isolation

Evolve service failure does not automatically terminate the host process. The host receives an explicit unavailable or failure outcome. The Evolve supervisor may replace quarantined workers. Host retries follow Section 31, Remote Resilience And Fallback.

A failed Evolve service must not corrupt host in-memory state. Network partitions produce uncertain availability and possibly uncertain write outcomes. The host must not assume remote rollback.

### Readiness And Health

Bridge health must distinguish:

- Host application alive.
- Adapter configured.
- Evolve application booted.
- Evolve application ready.
- Remote target reachable.
- Protocol compatible.
- Required capability available.
- Authentication configuration valid.
- Worker safe for reuse.

Rules:

- Process liveness does not prove Bridge readiness.
- Readiness and health must distinguish local host health from remote Evolve availability.
- A non-critical Bridge capability may be degraded without failing complete host readiness.
- A critical Bridge capability may cause host readiness failure.
- Criticality must be explicit.
- Health checks must avoid destructive business operations.
- Remote health checks must be bounded.
- Health output must not expose secrets.

## 33. Correlation And Observability

Bridge should propagate host request identifier, Bridge invocation identifier, Evolve execution identifier, safe principal or tenant reference, trace context, capability identifier, integration mode, adapter identifier and version, target identifier, outcome, fallback decision, retry count and duration.

Rules:

- Core must not depend on Insight or Observe.
- Bridge must work without optional observability packages.
- Correlation must remain execution-scoped.
- Remote trace propagation must be validated.
- Logs must not expose tokens or raw sessions.
- Exact metrics and spans belong to RFC 0007.

## 34. Security Boundaries And Considerations

Security considerations for Bridge start with the selected integration mode.

### Embedded Trust Model

> Embedded Bridge code, the host application and EvolvePHP execute inside one trusted PHP process.

Consequences:

- Bridge is not a sandbox.
- Host code can affect Evolve process memory.
- Evolve code can affect host process resources.
- Dependency provenance matters.
- A compromised host process compromises embedded Evolve execution.
- Service contracts reduce coupling but do not provide security isolation.
- Least-privilege service exposure is still required.
- Sensitive host services must not be exposed through broad container access.
- Untrusted capabilities require remote isolation.

### Remote Trust Model

Remote mode requires a network security boundary and network security policy including TLS where traffic can leave a trusted local development boundary, service authentication, operation-level authorization, request size limits, header allowlists, input validation, deadline limits, replay protection where appropriate, idempotency controls, rate limiting where appropriate, safe error responses, secret rotation, auditability and network access restrictions.

Endpoint URLs must come from trusted configuration. Arbitrary user-controlled targets are forbidden. Redirect following must be controlled. Private network access requires deliberate configuration. Identity claims require integrity protection. Internal administrative operations must not be exposed by default. Security reports follow `SECURITY.md`.

Remote security also references Section 28, Remote Protocol Boundary: arbitrary PHP serialization is forbidden and internal class names must not be protocol contracts.

### Secret Handling

Secrets remain in configuration or secret-management systems. Secrets must not appear in Bridge descriptors. Tokens must not be logged. Authentication headers must be redacted. Remote credentials need rotation support. Embedded mode must not expose all host secrets to every Evolve component. Error payloads must not reveal credentials. Migration logs must not contain production data unnecessarily. Test fixtures must use non-production secrets.

Additional security considerations:

- Embedded mode is one trust and failure domain.
- Remote mode is a network trust boundary.
- Identity assertions require validation.
- Authorization must remain operation-specific.
- Raw sessions must not cross remote boundaries.
- User-controlled remote targets are forbidden.
- Header forwarding uses allowlists.
- Payload limits are required.
- Write retries require idempotency.
- Uncertain writes must be reconciled.
- Dual writes can corrupt data.
- Cross-tenant context leakage remains a vulnerability.
- Migration tooling must protect production data.
- Bridge packages and host adapters require dependency review.

## 35. Deployment Boundaries And Compatibility Matrix

Embedded mode normally shares PHP process, Composer dependency graph, release deployment, failure domain and scaling unit.

Remote mode normally separates PHP process, Composer dependency graph, release deployment, failure domain, scaling unit, health and rollback.

Rules:

- Deployment documentation must identify the selected mode.
- Remote compatibility must account for client and server versions.
- Embedded upgrades must account for the host dependency graph.
- Sidecar deployment must define startup ordering and readiness.
- Evolve Deploy is not required and remains outside the runtime graph.

Every released host adapter should document tested combinations such as:

```text
Bridge adapter version
EvolvePHP version
host framework
host framework version
PHP version
integration mode
protocol version where remote
```

Compatibility matrix rules:

- Untested combinations must not be advertised as officially supported.
- Composer acceptance alone is insufficient.
- A host-framework minor update may require adapter validation.
- Compatibility may differ between embedded and remote modes.
- Remote client compatibility may extend further than embedded PHP compatibility.
- Matrix updates require changelog entries.
- Exact supported Laravel and Symfony versions are not selected by this RFC.

## 36. Incremental-Modernisation Workflow

Adopted workflow:

```text
1. Inventory the legacy capability
2. Define the bounded migration scope
3. Identify current owners and dependencies
4. Define the target Evolve contract
5. Select embedded or remote mode
6. Define route and data ownership
7. Define identity and security mapping
8. Build the adapter and target capability
9. Add parity and integration tests
10. Prepare data migration
11. Deploy in non-authoritative mode where safe
12. Validate observability and rollback
13. Perform controlled cutover
14. Monitor and reconcile
15. Remove legacy ownership after acceptance
```

Rules:

- One bounded capability should be migrated at a time.
- Dependencies must be explicit.
- Migration must have measurable acceptance criteria.
- The legacy implementation remains authoritative until cutover.
- Decommissioning occurs only after validation.
- Bridge must not become a permanent excuse for undocumented coupling.

## 37. Capability Selection And Contract-First Migration

Prefer capabilities with clear inputs and outputs, limited data ownership, measurable behaviour, few hidden globals, limited cross-module transactions, good test coverage, independent deployment or route boundary, and meaningful business value.

Avoid starting with the most coupled core transaction, a capability with unknown data ownership, a capability requiring simultaneous rewrite of the entire host, a security-critical workflow without parity tests, or a workflow with uncontrolled dual writes.

Before implementation, define operation names, inputs, outputs, validation, error semantics, authentication, authorization, idempotency, side effects, data ownership, timeout behaviour, compatibility version and rollback behaviour.

Rules:

- Contracts must use application or Bridge DTOs, not legacy model objects.
- Internal host classes must not become remote protocol types.
- Contracts must be testable independently.
- Breaking contract changes follow RFC 0003.
- Temporary compatibility fields require deprecation plans.

## 38. Cutover, Shadow Execution And Temporary Synchronization

The host remains the outer entry point initially. Selected routes or operations are delegated to Evolve. Ownership expands gradually, and legacy implementation is removed only after accepted cutover.

Route switches must be reversible when data state allows. Observability must distinguish legacy and Evolve handling. Cutover percentages or cohorts may be used only with deterministic ownership. Authoritative write ownership follows Section 25, Data Ownership. Exact traffic-management tooling is outside this RFC.

Shadow execution may be used for safe comparison when the shadow path is read-only or side effects are suppressed, sensitive data handling is approved, performance impact is bounded, results are compared safely, shadow failures do not affect users, and shadow output is not presented as authoritative.

Shadow execution must not:

- Duplicate payments.
- Send duplicate messages.
- Mutate shared records.
- Create duplicate external side effects.
- Bypass consent or security policy.

Dual writes are not the default migration strategy. Uncoordinated dual writes are forbidden.

Temporary dual writes require explicit owner, idempotency, ordering policy, failure handling, reconciliation, observability and removal date. An outbox or durable synchronization pattern is preferred where appropriate. A failed second write must not be hidden. Dual-write removal is part of migration completion.

## 39. Data Migration And Source-Of-Truth Transition

A data migration plan should define source and target schema, identifier mapping, validation rules, backfill process, incremental synchronization, cutover checkpoint, reconciliation, error handling, retention, rollback compatibility, personal-data handling and deletion ownership.

Rules:

- Data must not be copied without purpose and ownership.
- Backfills must be restartable or checkpointed.
- Validation must compare meaningful totals and invariants.
- Schema changes should support the planned rollback window.
- Production data must not be placed in logs.
- Exact migration tools remain outside RFC 0006.

Explicit states:

```text
legacy authoritative
migration syncing
Evolve authoritative
legacy read-only
legacy retired
```

State transitions must be documented. The active authoritative writer follows Section 25, Data Ownership. Read paths must know which source is current. Cache invalidation must follow ownership changes. Returning to legacy authority requires compatible data. Both authoritative is not a valid stable state.

## 40. Rollback And Legacy Decommissioning

A rollback plan must account for:

- Route ownership.
- Data written after cutover.
- External side effects.
- Queued events.
- Cache state.
- Authentication or session state.
- Schema compatibility.
- Protocol versions.
- Idempotency records.
- Audit records.

Rules:

- Rollback is not merely changing a route flag.
- Writes completed in Evolve may need reverse migration or compensation.
- An uncertain remote operation must be reconciled before fallback.
- Rollback criteria must be measurable.
- The rollback window must be documented.
- Irreversible steps require explicit approval.

Legacy decommissioning may happen only after Evolve ownership is accepted, data reconciliation passes, required observability is stable, rollback window closes or an alternative recovery plan exists, legacy traffic is zero, legacy scheduled jobs are disabled, legacy event consumers are disabled, legacy write access is removed, documentation is updated, and dead code and configuration are removed deliberately.

Bridge mappings must not remain indefinitely without an owner.

## 41. Failure Drills

Before production cutover, test:

- Evolve unavailable.
- Bridge adapter misconfigured.
- Authentication failure.
- Authorization failure.
- Protocol mismatch.
- Timeout before processing.
- Timeout after possible processing.
- Retry of an idempotent operation.
- Duplicate delivery.
- Partial data migration.
- Reset failure.
- Worker quarantine.
- Host rollback.
- Evolve rollback.
- Dependency conflict in embedded mode.

Results must be documented.

## 42. Testing Requirements

Future implementation tests must cover at minimum:

### Generic Contracts

- Host types do not leak into generic contracts.
- Evolve internal services are inaccessible.
- Explicit capability mapping.
- Invalid configuration.
- Compatibility mismatch.
- Duplicate application boot prevention.
- Duplicate execution-scope prevention.

### Embedded Mode

- One coherent Composer graph.
- Host lifecycle ownership.
- One Evolve boot.
- One execution scope per delegation.
- Request translation.
- Response translation.
- Authentication translation.
- Scope cleanup on success.
- Scope cleanup on failure.
- Reset failure quarantine.
- No host request retained after execution.
- Deterministic route ownership.

### Remote Mode

- Protocol compatibility.
- Service authentication.
- Operation authorization.
- Deadline propagation.
- Timeout handling.
- Idempotent retry.
- Duplicate idempotency key.
- Non-idempotent retry prevention.
- Safe error payload.
- Correlation propagation.
- Circuit-open behaviour.
- Remote unavailable behaviour.
- Uncertain write outcome.
- Request-size limits.

### Migration

- Legacy authoritative before cutover.
- Evolve authoritative after cutover.
- No dual authoritative writes.
- Read-only shadow execution.
- Data reconciliation.
- Cutover.
- Rollback with post-cutover data.
- Legacy decommissioning criteria.

RFC 0006 itself adds documentation-policy tests only.

## 43. Architecture-Enforcement Direction

Future tooling should enforce:

- No Core dependency on Bridge.
- No host-framework types in generic Bridge contracts.
- No Laravel types outside Laravel adapter packages.
- No Symfony types outside Symfony adapter packages.
- No direct use of legacy model objects in remote protocol contracts.
- Explicit compatibility constraints.
- Explicit route ownership.
- No unsupported duplicate Evolve boot.
- No invalid lifetime mapping.
- No ignored Composer platform requirements.
- Protocol-schema compatibility tests.
- Adapter compatibility matrices.
- Repeated execution and reset tests.
- Security and idempotency test coverage.

Do not add architecture tooling in this task.

## 44. Operational Documentation

A released Bridge adapter should document supported host versions, supported PHP versions, supported Evolve versions, installation, integration mode, lifecycle ownership, route configuration, authentication mapping, authorization model, session behaviour, timeout and retry policy, failure and fallback policy, health checks, logs and correlation, upgrade process, rollback process, and known limitations.

A remote Bridge deployment should additionally document protocol version, service authentication, TLS, endpoint configuration, network policy, idempotency, rate limits, circuit breaking, deployment and scaling, and client/server compatibility.

## 45. Explicit Non-Goals

- This RFC does not create Bridge packages.
- It does not create Laravel integration.
- It does not create Symfony integration.
- It does not create a PSR adapter.
- It does not create remote client or server code.
- It does not publish protocol endpoints.
- It does not publish JSON schemas.
- It does not implement authentication propagation.
- It does not implement route mounting.
- It does not implement container mapping.
- It does not implement distributed transactions.
- It does not implement event transport.
- It does not implement data migration tooling.
- It does not migrate EvolvePHP 1 source code.
- It does not make incompatible Composer dependencies safe.
- It does not support same-process PHP versions below the EvolvePHP minimum.
- It does not promise Laravel or Symfony version support.
- It does not promise Bridge delivery in Alpha.
- It does not guarantee zero downtime.
- It does not guarantee automatic rollback.
- It does not implement Evolve Deploy.
- It does not begin RFC 0007.
- It does not modify Composer metadata.

## 46. Consequences And Tradeoffs

### Positive Consequences

- Existing applications can modernize incrementally.
- Legacy PHP applications can use remote Evolve capabilities.
- Host-framework coupling remains isolated.
- Route and data ownership become explicit.
- Same-process and remote tradeoffs are clear.
- Security context propagation is governed.
- Remote protocol compatibility is versioned.
- Failures and retries are safer.
- Migration cutovers become measurable.
- Rollback planning becomes realistic.
- Evolve Core remains host-neutral.
- Future Laravel and Symfony adapters can share generic contracts.

### Negative Consequences

- Bridge adapters require substantial maintenance.
- Same-process dependency resolution may block some hosts.
- Remote mode adds latency and network failure.
- Remote mode requires service authentication and operations work.
- Data ownership changes require careful migration.
- Dual-run validation can be expensive.
- Strict failure handling may reduce apparent availability.
- Idempotency and reconciliation add complexity.
- Rollback can require data reversal, not only routing changes.
- Compatibility matrices increase release work.
- Supporting multiple host frameworks consumes maintainer capacity.
- Bridge may become long-lived transitional infrastructure if migrations are not completed.

These costs are accepted and must remain visible.

## 47. Alternatives Considered

### Require A Complete Rewrite

Rejected because it creates high delivery risk and blocks incremental adoption.

### Let Evolve Automatically Scan And Intercept Host Routes

Rejected because route ownership must be explicit and deterministic.

### Make Core Depend Directly On Laravel And Symfony

Rejected because Core must remain host-neutral.

### Use Separate Conflicting Vendor Directories In One Process

Rejected because PHP class loading and dependency compatibility are not safely isolated that way.

### Allow Embedded Mode On Unsupported PHP Through Compatibility Shims

Rejected because it conflicts with RFC 0003 and weakens the EvolvePHP baseline.

### Share Native PHP Sessions Over Remote Bridge

Rejected because separate processes do not share safe session memory and the boundary requires explicit identity propagation.

### Serialize PHP Objects Remotely

Rejected because it couples implementations and creates security and compatibility risks.

### Retry Every Remote Failure Automatically

Rejected because state-changing requests may already have produced side effects.

### Fall Back Automatically To Legacy After Remote Timeout

Rejected because the Evolve operation may have completed, creating duplicate side effects.

### Use Distributed Transactions By Default

Rejected because they are operationally complex and unavailable across many migration boundaries.

### Permit Both Systems To Write Authoritatively

Rejected because unclear ownership creates divergence and data corruption.

### Treat Rollback As A Feature Flag Only

Rejected because post-cutover writes and side effects must be reconciled.

### Build All Adapters Before Accepting The RFC

Rejected because governance should define the boundary before implementation.

## 48. Governance

- RFC 0006 is authoritative for Evolve Bridge and incremental-modernisation architecture.
- RFC 0001 remains authoritative for product scope and Bridge's Beta direction.
- RFC 0002 remains authoritative for package and public API boundaries.
- RFC 0003 remains authoritative for versioning and compatibility claims.
- RFC 0004 remains authoritative for application/module/plugin lifecycle.
- RFC 0005 remains authoritative for execution scope, reset and quarantine.
- RFC 0007 will define diagnostic and OpenTelemetry details.
- Adapter implementations must preserve the accepted ownership and safety model.
- Host-specific convenience APIs must not redefine generic Bridge policy.
- Material reversals require a superseding RFC.
- Supported-adapter claims require repository evidence.
- Same-process integration must never bypass PHP or dependency constraints.
- Remote protocol implementations must remain versioned and testable.
- Implemented status requires code, tests and compatibility evidence.
