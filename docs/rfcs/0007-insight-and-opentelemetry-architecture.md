# RFC 0007: Insight and OpenTelemetry Architecture

- Status: Accepted
- Authors: Josiah Chinedu Gerald
- Created: 2026-07-30
- Target release: EvolvePHP 2.0 Beta
- Decision type: Diagnostics, instrumentation and production telemetry architecture
- Depends on: RFC 0001, RFC 0002, RFC 0003, RFC 0004, RFC 0005, RFC 0006
- Supersedes: None
- Superseded by: None

## 1. Summary

RFC 0007 defines the accepted architecture and governance policy for generic framework instrumentation, Evolve Insight and Evolve Observe.

```text
generic framework instrumentation
        |
        +-- Evolve Insight
        |       local and development diagnostics
        |
        +-- Evolve Observe
                production OpenTelemetry integration
```

Core emits or exposes generic instrumentation through stable, implementation-neutral contracts. Insight consumes generic instrumentation for local human-oriented diagnostics. Observe adapts generic instrumentation to production telemetry and OpenTelemetry.

Core operates without Insight. Core operates without Observe. Insight operates without Observe. Observe operates without Insight. Neither optional package owns the application lifecycle. RFC 0007 defines governance, not implementations.

## 2. Goals

- Observability by design with clear Insight versus Observe separation, optional diagnostic and telemetry packages, and Core independence.
- Execution-scoped context isolation, OpenTelemetry interoperability, vendor neutrality, stable generic instrumentation contracts, and trace, metric and log correlation.
- Deterministic lifecycle integration, safe persistent-worker reuse, bounded performance overhead, bounded memory usage, secure defaults, data minimization, redaction and cardinality controls.
- Explicit propagation, testable support claims, no required proprietary backend, no application-database telemetry dependency, and compatibility with traditional, persistent, embedded Bridge and remote Bridge runtimes.

## 3. Non-Goals Summary

RFC 0007 does not:

- Implement Insight, Observe, an OpenTelemetry SDK, an exporter, OTLP or Collector deployment.
- Build an observability backend, log-management platform, application-performance-monitoring vendor, complete dashboard or hosted telemetry billing model.
- Replace PSR-compatible logging.
- Guarantee zero instrumentation overhead.
- Capture all application data.
- Guarantee delivery of every telemetry item.
- Make telemetry an audit ledger.
- Make sampling a security control.
- Publish PHP interfaces.
- Select exact package versions.
- Implement RFCs 0001-0006.

## 4. Terminology

- Instrumentation: code or framework behaviour that records structured information about application operations.
- Instrumentation hook: a generic lifecycle or operation boundary through which structured observation data may be consumed.
- Diagnostic record: a local human-oriented record intended primarily for development, testing or troubleshooting.
- Diagnostic batch: the bounded set of Insight records associated with one execution.
- Telemetry: structured traces, metrics and logs produced for operational observation.
- Signal: a telemetry category such as trace, metric or log.
- Trace: a representation of causally related operations across one or more processes.
- Span: one timed operation within a trace.
- Root execution span: the span representing one Evolve execution when tracing is active and the execution does not already continue a valid upstream trace relationship.
- Metric: a numeric measurement aggregated over time.
- Log record: a timestamped event record that may be correlated with execution and trace context.
- Resource: information describing the entity producing telemetry, such as service or process identity.
- Context: execution-associated immutable propagation state used by telemetry and other cross-cutting concerns.
- Propagation: extraction and injection of supported context through an explicit carrier.
- Baggage: application-defined propagated key/value context that is separate from span attributes and is untrusted by default.
- Semantic convention: a documented naming and meaning policy for telemetry attributes, operations, metrics and events.
- Exporter: a component that sends detached telemetry data to another system.
- Collector: an independently deployed component that may receive, process and export telemetry.
- Sampling: a policy deciding which trace data is recorded or exported.
- Cardinality: the number of distinct values observed for a metric attribute or another aggregation dimension.
- Redaction: removal, replacement or suppression of sensitive information before storage or export.
- Instrumentation failure: a failure in observation code that is distinct from the application operation being observed.

## 5. Product-Area Separation

### Evolve Insight

- Local and development diagnostics.
- Human-oriented inspection.
- Execution diagnostic batches.
- Framework lifecycle timelines.
- Query, cache, event, queue, exception and operation inspection where adapters provide safe data.
- Bounded local retention.
- Optional user interface direction.
- Not the production telemetry backend.
- Not required for Observe.
- Not required for Core correctness.

### Evolve Observe

- Production telemetry integration.
- OpenTelemetry-oriented.
- Traces, metrics and logs.
- Context propagation.
- Resource identity.
- Semantic conventions.
- Exporter and Collector integration.
- Vendor-neutral by design.
- Not a complete storage, search, alerting or visualization backend.
- Does not depend on Insight.
- Is not required for Core correctness.

A package must not silently combine both responsibilities merely for convenience.

## 6. Package Boundaries

RFC 0007 reaffirms RFC 0002.

Accepted direction:

```text
evolvephp/contracts
    ^
generic Core instrumentation contracts
    ^
evolvephp/insight

evolvephp/contracts
    ^
generic Core instrumentation contracts
    ^
evolvephp/observe
    ^
selected OpenTelemetry standards and implementation packages
```

This diagram shows conceptual inward dependency direction. Exact low-level edges remain subject to package-structure implementation.

Rules:

- Core does not depend on Insight.
- Core does not depend on Observe.
- Contracts do not depend on Insight.
- Contracts do not depend on Observe implementation packages.
- Insight does not depend on Observe.
- Observe does not depend on Insight.
- Observe may depend on selected OpenTelemetry packages.
- Insight-specific storage stays in Insight.
- Observe-specific exporters stay in Observe.
- Host-framework instrumentation stays in matching Bridge adapters.
- Runtime-specific instrumentation stays in Runtime adapters.
- Production packages do not depend on Testing.

Do not create a new package solely for instrumentation in this RFC. A later implementation decision may place small generic instrumentation contracts in `evolvephp/contracts` or appropriate Core boundaries when genuinely shared.

## 7. Generic Instrumentation Boundary

Core may expose generic instrumentation for:

- Application boot.
- Discovery.
- Validation.
- Dependency resolution.
- Registration.
- Service-definition freeze.
- Boot.
- Ready transition.
- Execution creation.
- Routing.
- Middleware.
- Handler invocation.
- Module operation.
- Plugin operation.
- Execution termination.
- Scope closure.
- Reset.
- Shutdown.
- Failures.
- Quarantine.

Rules:

- Generic instrumentation must not expose Insight classes.
- Generic instrumentation must not expose OpenTelemetry SDK classes.
- Generic instrumentation must not expose exporter classes.
- Instrumentation contracts must remain small.
- Instrumentation must not become a general event bus.
- Instrumentation hooks must not carry business-command responsibility.
- Instrumentation consumers must not modify lifecycle ordering.
- Instrumentation callbacks must be bounded.
- Instrumentation must not require a network connection.
- Instrumentation data must be structured.
- Instrumentation failures must be distinguishable from observed-operation failures.
- Exact interface signatures are deferred.

## 8. Instrumentation Event Model

A generic observation should be able to represent:

- Operation name.
- Operation category.
- Lifecycle phase.
- Start time.
- End time or duration.
- Outcome.
- Safe error classification.
- Execution identifier.
- Component identifier where applicable.
- Module or plugin identifier where applicable.
- Runtime kind.
- Bridge mode where applicable.
- Safe structured attributes.
- Parent operation relationship where applicable.

Rules:

- Observation data must be immutable or frozen before asynchronous export.
- Raw mutable request objects must not be observation payloads.
- Container objects must not be observation payloads.
- Database connections must not be observation payloads.
- Throwable objects may be translated into safe structured error data, but must not be retained indefinitely.
- Secrets must not enter generic observation data.
- Attribute values require type and size limits.
- Exact DTOs are deferred.

## 9. Diagnostic And Telemetry Lifecycle

Adopted lifecycle:

```text
application boot
    -> configure generic instrumentation
    -> initialize enabled Insight/Observe consumers
    -> application ready

execution begin
    -> extract validated upstream propagation where applicable
    -> create execution identifier
    -> establish execution-scoped observation context
    -> begin diagnostic batch when Insight is enabled
    -> begin or continue trace relationship when Observe is enabled
    -> handle application work
    -> record operations, metrics and logs
    -> capture primary outcome
    -> run termination hooks
    -> finish active execution observations
    -> end active spans
    -> detach trace, span, baggage and propagation context
    -> freeze detached diagnostic and telemetry data
    -> close execution-scoped resources
    -> reset reusable participants
    -> clear remaining ambient execution context
    -> perform bounded persistence or export using detached data
    -> decide whether process is safe for reuse

application shutdown
    -> stop accepting work
    -> perform bounded final flush
    -> shut down instrumentation consumers
```

Rules:

- RFC 0004 remains authoritative for application lifecycle.
- RFC 0005 remains authoritative for execution scope, reset and quarantine.
- Termination hooks may record final observations before active context is ended.
- Active telemetry context must end and detach before execution-scope closure.
- Post-closure persistence or export may use detached immutable data only.
- Post-closure work must not reactivate a closed execution context.
- A later execution must not inherit prior context.
- Shutdown flush must be bounded.
- Export completion is not a prerequisite for application business success unless an application explicitly defines a separate audit requirement outside normal telemetry.

## 10. Execution Correlation

Every diagnostic or telemetry record should support safe correlation through:

- Evolve execution identifier.
- Trace identifier when tracing is active.
- Span identifier where relevant.
- Upstream correlation identifier where supplied.
- Bridge invocation identifier where applicable.
- Runtime kind.
- Safe module or component identifier.

Rules:

- The Evolve execution identifier is not automatically the trace ID.
- A trace may include multiple execution identifiers across services.
- One execution may contain multiple spans.
- Metrics must not use execution identifiers as normal dimensions.
- Correlation fields must not contain secrets.
- Identifiers must have documented size limits.
- Logs outside an active execution must not inherit the previous execution's identifiers.

## 11. Insight Architecture

Insight consumes generic instrumentation and constructs one bounded diagnostic batch per execution.

A batch may include:

- Execution summary.
- Lifecycle timeline.
- Route and middleware timeline.
- Module and plugin operations.
- Query summaries.
- Cache operations.
- Events.
- Queue or job information.
- Safe request metadata.
- Safe response metadata.
- Exceptions.
- Logs.
- Memory measurements.
- Timing measurements.
- Reset and cleanup results.

Rules:

- Each batch has exactly one execution identifier.
- A batch must not merge unrelated executions.
- The batch becomes immutable before post-scope persistence.
- Batch size must be bounded.
- Record count must be bounded.
- Individual field size must be bounded.
- Collection must support disabling expensive categories.
- Insight failure must not normally fail application handling.
- Insight must not be required for reset correctness.
- Insight storage must remain outside Core.
- Insight must not become an application business database.
- Exact collector and storage APIs are deferred.

## 12. Insight Enablement, Storage And Interface Direction

Insight is optional, deliberately enabled, and not required for Core correctness. Insight is not a production telemetry backend.

### Environment And Enablement

- Enabled deliberately in development.
- Available in testing.
- Insight is disabled in production unless explicitly configured.
- Production enablement requires security and retention review.
- Environment name alone must not be the only security control.
- Production diagnostics must not expose stack traces publicly.
- Debug mode must not disable redaction automatically.
- Insight must document its performance impact.
- Insight must support complete disablement.
- Disabled Insight must not create local diagnostic storage.
- The absence of Insight must not remove generic lifecycle correctness.

### Storage And Retention

Potential local storage directions may include memory for one test execution, bounded files, bounded local database storage or replaceable local diagnostic stores.

Rules:

- Storage implementation is deferred.
- Retention must be explicit.
- Maximum storage size must be bounded.
- Eviction behaviour must be documented.
- Corrupt diagnostic storage must not prevent application startup by default.
- Storage writes must be bounded.
- Diagnostic data must not be silently retained forever.
- Application business databases must not be the default production telemetry store.
- Storage must support redaction before persistence.
- Storage must not retain live execution objects.

### Interface Direction And Access Control

A future Insight interface may display execution lists, timelines, queries, events, cache activity, logs, exceptions, module and plugin lifecycle, reset outcome and trace correlation.

Rules:

- RFC 0007 does not implement a UI.
- UI availability is not required for initial generic instrumentation.
- UI routes must be explicitly enabled.
- UI routes must not be silently exposed in production.
- Remote access to an Insight interface requires authentication and authorization.
- Access control is mandatory outside explicitly local-only environments.
- Local development access must still avoid unnecessary secret exposure.
- Destructive actions are outside the initial Insight direction.
- Display logic must preserve redaction.
- The interface must not expose raw credentials or tokens.

## 13. Observe Architecture

Observe adapts generic instrumentation and application telemetry to OpenTelemetry-compatible concepts.

Observe may provide:

- Trace integration.
- Metric integration.
- Log correlation or log-signal integration.
- Context propagation.
- Resource configuration.
- Semantic-convention mapping.
- Sampling configuration.
- Processor integration.
- Exporter integration.
- OTLP direction.
- Collector integration.

Rules:

- Observe is optional.
- Core must operate without Observe.
- Observe does not depend on Insight.
- Observe must not become a telemetry database.
- Observe must not become a proprietary backend requirement.
- Observe configuration must not redefine Core lifecycle.
- Exact OpenTelemetry PHP dependencies are deferred.
- Official compliance claims require implementation and conformance evidence.

## 14. OpenTelemetry API And SDK Boundary

Conceptual direction:

- Application and library instrumentation should depend on stable APIs or Evolve public contracts.
- SDK configuration belongs at the application or Observe integration boundary.
- Exporters are implementation details.
- Instrumentation libraries must not configure a global exporter unilaterally.
- Modules and plugins must not replace application telemetry providers silently.
- Applications control providers, processors, readers, exporters and sampling.
- Core must not instantiate an OpenTelemetry SDK directly.
- Generic framework contracts must not expose concrete SDK implementations.
- No-op behaviour must be possible when Observe is absent.

Exact PHP class names are not selected in this RFC.

## 15. Trace Model

Tracing should represent one execution and its child operations.

Execution types may map conceptually to:

- HTTP server operation.
- Queue or message consumer operation.
- Scheduled job operation.
- CLI command operation.
- Explicit worker task.
- Embedded Bridge delegated operation.
- Remote Bridge client or server operation.

Rules:

- One Evolve execution must not create multiple competing root execution spans unintentionally.
- Valid upstream trace context may be continued.
- Invalid or untrusted context must not be accepted blindly.
- An execution without valid upstream context may begin a new trace when sampled.
- Child spans should represent meaningful operations.
- Spans must not be created for every trivial function call.
- Span nesting must reflect real causal or ownership relationships.
- Spans must end on success, failure and cancellation.
- Active spans must not survive execution closure.
- Exact span names and kinds follow accepted semantic conventions where suitable.
- Exact mapping is deferred to implementation.

## 16. Duplicate Instrumentation Prevention

Evolve instrumentation may coexist with:

- Host-framework instrumentation.
- Runtime auto-instrumentation.
- HTTP client instrumentation.
- Database instrumentation.
- Queue instrumentation.
- Vendor agents.

Rules:

- The same operation should not be represented by duplicate framework spans.
- Host and Evolve Bridge adapters must define ownership of the outer span.
- Embedded mode may continue the host execution span or create a clearly nested delegated span.
- Remote mode must use explicit propagation.
- Instrumentation must detect or configure ownership where practical.
- Duplicate instrumentation must be documented as a compatibility issue.
- Disabling one instrumentation source must be supported where practical.
- Span suppression mechanics are deferred.

## 17. Trace Naming, Attributes And Events

Trace and span names must be stable enough for operational use, low-cardinality, based on operation identity rather than raw input, independent of user-supplied identifiers, and compatible with applicable semantic conventions.

Avoid span names containing raw URLs, query strings, record IDs, user IDs, tenant IDs, email addresses, random tokens, full SQL statements or exception messages. Route templates or named operations are preferred over raw paths where applicable. Custom Evolve span naming requires documentation and tests.

Attributes may include safe information such as:

- Execution kind.
- Route name or normalized route template.
- Module identifier.
- Plugin identifier.
- Lifecycle phase.
- Bridge integration mode.
- Runtime adapter identifier.
- Safe outcome classification.
- Error type.
- Retry count.
- Queue or operation name where bounded.
- Framework and package versions.

Rules:

- Standard semantic conventions take precedence where applicable.
- Custom attributes use a documented Evolve-owned namespace.
- Evolve attributes must not use the reserved `otel.*` namespace.
- Attribute values must be bounded.
- High-cardinality values require explicit justification.
- Sensitive data must be excluded or redacted.
- Error events must not duplicate complete stack traces unnecessarily.
- Exact attribute names are deferred where conventions remain unstable.

## 18. Metrics Model And Cardinality

Metrics should answer bounded operational questions such as:

- Execution count.
- Execution duration.
- Error count.
- Active execution count.
- Route or operation duration.
- Module lifecycle duration.
- Reset duration.
- Reset failure count.
- Worker quarantine count.
- Queue processing duration.
- Bridge invocation duration.
- Export failure count.
- Dropped telemetry count.
- Insight dropped-record count.

Rules:

- Metric instruments must have clear semantic meaning.
- Units must be documented.
- Counter and histogram behaviour must be selected correctly.
- Metric names must be stable.
- Metric attributes must be low-cardinality.
- Metrics must not contain secrets.
- Metrics must not use execution ID as a dimension.
- Metrics must not use trace ID or span ID as dimensions.
- Metrics must not use user ID as a dimension.
- Metrics must not use tenant ID as a dimension by default.
- Metrics must not use raw URL as a dimension.
- Metrics must not use exception messages as dimensions.
- Exact instruments are deferred.

Every metric attribute requires cardinality review.

Generally acceptable dimensions may include bounded values such as execution kind, normalized route name, module identifier, plugin identifier, outcome category, error category, runtime adapter, Bridge mode, queue name when explicitly bounded, and environment or deployment identity through resource data rather than repeated metric labels where appropriate.

Generally forbidden metric dimensions include execution identifier, trace identifier, span identifier, request identifier, user identifier, tenant identifier, session identifier, email address, IP address, raw URL, raw SQL, stack trace, arbitrary exception message and unbounded customer data.

A value being useful for trace investigation does not make it safe as a metric dimension.

## 19. Logs And Log Correlation

RFC 0007 does not replace existing logging abstractions.

Direction:

- Existing application logs may be correlated with execution and trace context.
- Observe may adapt log records to an OpenTelemetry log signal where implemented.
- Insight may display safe logs associated with one execution.
- Logging context belongs to execution scope.
- Logs outside execution scope must not inherit stale context.

Rules:

- Core must not require a particular logging implementation unnecessarily.
- Log correlation must avoid global mutable request state.
- Trace and span identifiers may be added when available.
- Execution identifier may be added safely.
- Secrets and tokens must not be added.
- Logger processors must clear context after execution.
- A log record must not become a metric dimension automatically.
- Exporting the same log through multiple pipelines must be controlled.
- Exact PSR logging integration remains deferred.

## 20. Resource Identity

Observe should describe the telemetry-producing entity through resource information.

Resource direction may include:

- Service name.
- Service version.
- Service instance identity.
- Deployment or environment identity.
- Runtime identity.
- Host or container information when supplied safely.
- Evolve framework version.
- Bridge adapter identity where relevant.

Rules:

- Use stable semantic conventions where applicable.
- Resource identity is not user identity.
- Resource identity is not tenant identity.
- Resource values must be configured or discovered safely.
- Unbounded request values must not become resource attributes.
- Secrets must not appear in resource data.
- Resource configuration is application-level, not per request.
- Exact resource attributes are deferred.

## 21. Semantic And Evolve Convention Governance

This section is the Semantic-convention policy for RFC 0007. It aligns with OpenTelemetry concepts without pinning a temporary OpenTelemetry specification version.

### OpenTelemetry Semantic Conventions

When implementation begins:

- Prefer applicable stable OpenTelemetry semantic conventions.
- Review the stability of each convention group used.
- Do not assume every convention is stable.
- Do not redefine an existing standard attribute with different meaning.
- Do not place Evolve-specific meaning under a standard namespace.
- Avoid custom conventions where a suitable standard exists.
- Do not delay foundational instrumentation indefinitely merely because one optional convention remains unstable.

Exact semantic-convention versions belong to implementation and release documentation.

### Custom Evolve Conventions

Custom Evolve conventions may be required for module lifecycle, plugin lifecycle, service-definition freeze, execution scope reset, worker quarantine, Bridge mode, Insight diagnostic batches and Evolve-specific component identifiers.

Rules:

- Document custom Evolve conventions.
- Use one documented Evolve-owned attribute namespace.
- Do not select the final prefix casually.
- The prefix must not conflict with reserved OpenTelemetry namespaces.
- Names must be stable and low-cardinality.
- Meanings must be documented.
- Evolve attributes must not use the reserved `otel.*` namespace.
- Exact names are deferred to implementation work unless required by a later RFC.

### Stability And Compatibility

- Custom attributes must identify their stability.
- Experimental names must be visibly experimental.
- Stable custom names follow RFC 0003 compatibility rules.
- Version custom conventions where public compatibility requires it.
- Deprecate custom conventions with migration guidance.

## 22. Execution Context, Propagation And Baggage

### Context Lifecycle

Context belongs to exactly one execution. Telemetry context must be immutable or behave immutably.

Rules:

- Attaching context must have a matching detach operation.
- Context must detach on success, failure and cancellation.
- Context must not be retained by application singletons.
- Active context must not survive execution-scope closure.
- Context from one execution must not become the default for another.
- A later execution must not inherit prior context.
- Concurrent executions require independently isolated contexts.
- Context management must comply with RFC 0005.
- Context implementation must not rely on uncontrolled global mutable state.
- Exact PHP context mechanism is deferred.

### Carrier Extraction And Injection

Propagation may occur through HTTP headers, queue or message metadata, Bridge remote protocol fields, explicit CLI or job carriers where approved, and other runtime-specific carriers.

Rules:

- Extract at the outer runtime or Bridge boundary.
- Inject at an outgoing transport boundary.
- Carriers are treated as untrusted input.
- Propagation formats must be explicitly configured.
- W3C Trace Context and W3C Baggage are the initial interoperability direction where applicable.
- Other propagators may be supported through configuration.
- Core must not depend on transport-specific carrier classes.
- Duplicate or conflicting propagation headers require deterministic handling.
- Invalid context must not crash ordinary handling.
- Invalid context must not be trusted.
- Propagation fields require size limits.
- Secrets must not be propagated through trace context.

### Baggage Trust And Limits

Baggage is untrusted propagated context.

Rules:

- Baggage is not authorization data.
- Baggage is not authentication proof.
- Baggage is not a secret store.
- Baggage must not contain passwords.
- Baggage must not contain access tokens.
- Baggage must not contain session identifiers.
- Baggage must not contain raw personal data by default.
- Baggage keys require an allowlist where used by framework integrations.
- Baggage count and total size must be bounded.
- Baggage must not automatically become span attributes.
- Baggage must not automatically become log fields.
- Baggage must not automatically become metric attributes.
- Applications must explicitly select safe baggage values for telemetry enrichment.
- Baggage clears with execution context.
- Remote callers must not be able to force unbounded baggage storage.

## 23. Sampling

Sampling affects telemetry volume, not application correctness.

Rules:

- Sampling must not alter business behaviour.
- Sampling must not alter authorization.
- Sampling must not alter audit obligations.
- Sampling must not suppress application error handling.
- Sampling must be configurable.
- Trace sampling decisions must propagate consistently where applicable.
- Head sampling may be supported.
- Collector or backend tail sampling may be supported.
- Tail sampling must not be presented as an in-process guarantee.
- Errors are not guaranteed to be retained merely because they are errors unless the configured system provides that policy.
- Unsampled traces may still contribute safe metrics.
- Sampling must not create user- or tenant-specific discrimination without explicit approved policy.
- Exact samplers are deferred.

## 24. Diagnostic Capture And Data Classification

Insight may capture richer development information than Observe, but still requires safe defaults.

Potential records include normalized request metadata, response status, query duration, sanitized query shape or fingerprint, cache operation, event name, listener duration, queue operation, module operation, exception type, safe stack trace in approved environments, and memory and timing information.

Default exclusions include passwords, Authentication headers, Authorization headers, cookies, session identifiers, access tokens, refresh tokens, API keys, private cryptographic material, raw payment data, full request bodies, full response bodies, uploaded file contents, SQL binding values, arbitrary environment variables and complete configuration dumps.

Explicit capture of sensitive categories requires application-level policy and documentation.

Instrumentation data must be classified before capture.

Suggested categories:

```text
public operational metadata
internal operational metadata
personal data
authentication data
secret data
business-sensitive payload
regulated data
```

Rules:

- Secret data is never captured intentionally.
- Authentication data is excluded except safe method or outcome classifications.
- Personal data capture requires explicit policy and legal review where applicable.
- Business payload capture is disabled by default.
- Regulated data requires explicit approved handling.
- Classification applies before Insight storage and Observe export.
- Classification occurs before persistence or export.
- A local environment does not remove classification requirements.
- Exact organization-specific classifications remain application-owned.

## 25. Adapter Instrumentation Policy

Adapter instrumentation is future implementation work. It must satisfy the generic instrumentation, lifecycle, data-governance, context and metric-cardinality policies in this RFC.

### Database

Future database instrumentation may record operation type, database system, safe connection identity, duration, success or failure, sanitized statement shape or fingerprint, row count where safe and available, and transaction lifecycle.

Rules:

- SQL bindings are excluded by default.
- Credentials are always excluded.
- Raw statements require explicit policy.
- Query comments must not expose user data.
- Connection strings must be sanitized.
- Database instrumentation must not change transaction behaviour.
- Instrumentation failure must not leave transactions open.
- Database session-state cleanup follows RFC 0005.
- Exact adapters are deferred.

### HTTP

Future HTTP instrumentation may record normalized route, method, status, duration, request and response size where safely available, client or server operation role, safe network information, and error classification.

Rules:

- Prefer route templates over raw paths.
- Query strings are excluded by default.
- Authentication headers are excluded.
- Cookie contents are excluded.
- Request and response bodies are excluded by default.
- User-agent or IP capture requires privacy review.
- HTTP semantic conventions should be used where stable and appropriate.
- Bridge must prevent duplicate outer HTTP instrumentation.
- Exact HTTP adapters are deferred.

### Queue And Messaging

Future messaging instrumentation may record messaging system, queue or topic, operation type, message processing duration, delivery attempt, success or failure, safe message type, correlation context, and dead-letter outcome where available.

Rules:

- Message body is excluded by default.
- Message identifiers must not become metric dimensions when unbounded.
- Trace context must be extracted and injected explicitly.
- Duplicate delivery must not create incorrect business behaviour.
- Instrumentation must not acknowledge messages.
- Acknowledgement ownership remains with Runtime or adapter logic.
- Messaging semantic conventions should be used where stable and appropriate.
- Exact queue adapters are deferred.

### Cache

Future cache instrumentation may record operation type, cache system, hit or miss, duration, success or failure, and safe cache region.

Rules:

- Cache values are excluded.
- Raw keys are excluded by default.
- User- or tenant-specific keys must not be exposed.
- Cache-key hashes require security and cardinality review.
- Instrumentation must not change cache semantics.
- Metric dimensions must remain bounded.
- Exact cache adapters are deferred.

### Events And Listeners

Future event instrumentation may record event name, listener identifier, dispatch duration, listener duration, success or failure, and deferred or synchronous classification.

Rules:

- Event payloads are excluded by default.
- Instrumentation must not become a second event dispatch system.
- Observation listeners must not alter event ordering.
- Temporary instrumentation listeners belong to execution scope.
- Deferred callbacks must not leak to later executions.
- Event names used as dimensions require bounded naming policy.
- Exact dispatcher adapters are deferred.

## 26. Module And Plugin Instrumentation

Modules and plugins may:

- Emit generic application observations through approved contracts.
- Add safe attributes to their own operations.
- Define custom diagnostic categories.
- Provide instrumentation adapters.
- Register bounded instrumentation contributors during RFC 0004 registration.

They must not:

- Depend on Insight internals.
- Depend on Observe internals unless they are an explicit Observe adapter.
- Replace the application telemetry provider silently.
- Create unbounded metric dimensions.
- Capture secrets.
- Retain current execution context in application-lifetime instances.
- Change lifecycle ordering through instrumentation.
- Treat instrumentation callbacks as business-event handlers.
- Bypass application sampling or redaction policy.
- Claim persistent-worker safety without repeated-execution evidence.

An instrumentation plugin remains trusted in-process code under RFC 0004.

## 27. Runtime Adapter Responsibilities

Runtime adapters own:

- Extraction from runtime-specific carriers.
- Establishment of one execution observation context.
- Execution-kind classification.
- Outer operation ownership.
- Cancellation and deadline context.
- Ending active runtime observations.
- Detaching context.
- Triggering bounded post-scope export where integrated.
- Reset and quarantine reporting.
- Shutdown flush integration.

Rules:

- Runtime SDK types remain in Runtime packages.
- Core does not import FrankenPHP or RoadRunner types.
- Runtime adapters must not skip telemetry detachment.
- Runtime adapters must not begin another sequential execution with stale context.
- Runtime adapters must document duplicate instrumentation interactions.
- Persistent-runtime support requires repeated-execution telemetry tests.
- Exact Runtime APIs are deferred.

## 28. Bridge Instrumentation Responsibilities

RFC 0006 remains authoritative for Bridge security and protocol behaviour. RFC 0007 defines only the diagnostic and telemetry responsibilities around that boundary.

### Shared Bridge Responsibilities

Embedded Bridge must:

- Determine whether the host owns the outer trace operation.
- Translate validated trace context.
- Create at most one Evolve delegated operation context.
- Avoid duplicate root spans.
- Preserve host/Evolve ownership boundaries.
- Detach Evolve execution context.
- Report reset or quarantine failure to the host.

Remote Bridge must:

- Extract validated incoming propagation.
- Inject supported outgoing propagation.
- Preserve correlation identifiers safely.
- Apply baggage policy.
- Avoid trusting arbitrary identity or baggage headers.
- Distinguish host invocation, network client and Evolve server operations.
- Avoid copying secrets into telemetry.

Rules:

- Remote propagation is explicit.
- Trace propagation is not authentication.
- Telemetry context is not authorization.
- Remote timeouts retain the uncertain-outcome rules from RFC 0006.
- Exact Bridge instrumentation APIs are deferred.

### Insight Diagnostics

Insight may display embedded host/Evolve delegation timing, Bridge mode, translation duration, remote invocation duration, retry count, fallback decision, compatibility failure, safe protocol error category, Evolve execution identifier and trace correlation.

Rules:

- Insight must not expose remote credentials.
- Protocol payloads are excluded by default.
- Identity assertions must be redacted.
- Uncertain write outcomes must remain visible.
- Insight display must not convert failures into success.
- Insight must not become a remote protocol packet inspector by default.

### Observe And Trace Propagation

Observe may create or map host-to-Evolve delegated spans, remote client spans, remote server spans, retry observations, circuit-state metrics, failure and fallback metrics, protocol compatibility errors, and Bridge resource and adapter attributes.

Rules:

- Applicable semantic conventions should be followed.
- The same network operation must not be instrumented twice unintentionally.
- Retries must be represented without hiding the original logical operation.
- Retry and fallback remain observable.
- Fallback must be observable.
- Trace propagation must remain separate from authentication.
- Uncertain Bridge outcomes remain uncertain unless evidence proves a definite result.
- Uncertain outcomes must not be labelled as definite failure or definite success without evidence.

## 29. Error Recording And Instrumentation Failure Policy

Instrumentation may record error type, safe error category, lifecycle phase, component identifier, primary outcome, cleanup outcome, reset outcome, quarantine decision and retryability where part of a protocol contract.

Rules:

- The primary application failure remains primary.
- Instrumentation failure is reported separately.
- Cleanup failure must not erase the application failure.
- Stack traces require environment and redaction policy.
- Exception messages may contain sensitive information and are not safe dimensions.
- Error recording must not throw a replacement exception during ordinary operation.
- Exact exception event mapping is deferred.

Default failure-isolation policy:

- Instrumentation must fail independently from business handling.
- Insight collection failure must not normally fail the execution.
- Insight storage failure must not normally fail the execution.
- Exporter failure must not normally fail the execution.
- Collector unavailability must not normally fail the execution.
- Collector failure does not normally fail the execution.
- Metric export failure must not normally fail the execution.
- Log export failure must not normally fail the execution.

Runtime-safety exceptions:

- Failure to detach active telemetry context is a runtime-safety failure.
- Failure to clear execution telemetry context prevents proven worker reuse.
- Such isolation failure triggers RFC 0005 quarantine.
- Instrumentation must not silently corrupt lifecycle state.
- Configuration failure for an explicitly required critical observability integration may prevent readiness only when the application deliberately declares it critical.
- Critical observability integration affects readiness only when explicitly configured as critical.
- Criticality must be explicit and documented.

## 30. Export Architecture, Collector And OTLP

### Bounded Buffering And Backpressure

In-process telemetry queues must be bounded.

Rules:

- Queue capacity must be configurable.
- Memory use must be bounded.
- Full-queue behaviour must be explicit.
- Dropping telemetry is preferable to unbounded application memory growth for ordinary telemetry.
- Dropped telemetry should be counted where practical.
- Export retries must be bounded.
- Retry backoff must be bounded.
- Export must not block request completion indefinitely.
- Shutdown flush must have a deadline.
- A persistent exporter outage must not cause unbounded disk or memory growth.
- Durable audit data must use a separate explicitly designed system rather than ordinary telemetry buffering.
- Exact processors and queues are deferred.

### Collector Direction

The OpenTelemetry Collector is an optional external integration boundary.

Potential roles include receiving telemetry, batching, retrying, filtering, redacting, transforming, sampling, routing and exporting to one or more backends.

Rules:

- Core does not require a Collector.
- Insight does not require a Collector.
- Observe may export directly or through a Collector.
- Production deployments should consider a Collector when it improves isolation, batching, routing or vendor portability.
- Collector configuration is deployment infrastructure.
- Collector failure must follow bounded exporter behaviour.
- EvolvePHP does not implement the Collector.
- Evolve Deploy is not required.
- Exact Collector topology is deferred.

### OTLP Direction

OTLP is the preferred vendor-neutral export direction where supported.

Rules:

- RFC 0007 does not implement OTLP.
- Exact transports are deferred.
- Endpoint configuration must be trusted.
- TLS and authentication are required according to deployment boundaries.
- Headers and credentials must be redacted.
- Timeouts must be bounded.
- Retries must be bounded.
- OTLP success does not prove backend persistence.
- OTLP failure does not redefine application success.
- Protocol and package versions must be documented during implementation.
- Other exporters may remain optional adapters.

## 31. Redaction And Data Minimization

Redaction must occur before data leaves its permitted boundary.

Rules:

- Redact before Insight persistence.
- Redact before telemetry export.
- Redact before Observe export.
- Redact before logs are correlated or exported where possible.
- Redaction must cover nested structured values where supported.
- Redaction rules must be deterministic.
- Redaction failures must be observable safely.
- A failed redaction operation must not export the original sensitive value.
- Redaction configuration must not be logged with secrets.
- Modules and plugins must not bypass central policy.
- Exact redaction APIs are deferred.

Collect only data necessary for defined diagnostic or operational purposes.

Rules:

- Do not capture complete payloads by default.
- Do not capture every header.
- Do not capture complete headers.
- Do not capture every environment variable.
- Do not capture complete configuration.
- Do not capture object graphs.
- Do not capture database bindings by default.
- Do not capture SQL bindings by default.
- Do not capture file contents.
- Do not capture session contents.
- Do not capture authorization tokens.
- Prefer classifications and normalized names over raw values.
- Retention must match purpose.
- Diagnostic convenience does not override security policy.

## 32. Retention And Access Control

Insight and Observe have different retention ownership.

### Insight

- Local store controls bounded retention.
- Retention is short by default.
- Eviction must be predictable.
- Applications may disable persistence.

### Observe

- External backend or Collector controls most production retention.
- Evolve must document what it buffers locally.
- Local buffering must remain bounded.
- Evolve does not promise external backend retention.

Rules:

- Retention settings must not be confused with legal audit retention.
- Deletion responsibility must be documented.
- Diagnostic UI access requires control.
- Remote diagnostic UI access requires authentication and authorization.
- Personal-data retention requires application policy.
- Exact retention durations are deferred.

## 33. Security Considerations

- Telemetry can contain sensitive data.
- Baggage is untrusted.
- Trace context is not authentication.
- Metric dimensions can expose identifiers.
- Diagnostic UIs can expose application internals.
- Export endpoints are security-sensitive configuration.
- Export credentials require rotation.
- Collector endpoints require network controls.
- Local diagnostic storage requires filesystem and access controls.
- Plugins can attempt to bypass instrumentation policy.
- Remote telemetry propagation requires validation.
- Cross-tenant telemetry leakage is a vulnerability.
- Cross-user telemetry leakage is a vulnerability.
- Stale context in persistent workers is a vulnerability.
- Unbounded telemetry buffers can create denial of service.
- Security reports follow `SECURITY.md`.

## 34. Performance And Memory Safety

Instrumentation overhead must be measurable and bounded. These are the Performance requirements for future implementation.

Future implementation must measure:

- Disabled instrumentation overhead.
- Insight-enabled overhead.
- Observe-enabled overhead.
- Trace creation overhead.
- Metric recording overhead.
- Log-correlation overhead.
- Export queue memory.
- Insight batch memory.
- Shutdown flush duration.
- Persistent-worker memory growth.

Rules:

- Disabled optional integrations should approach no-op overhead.
- Expensive data capture must be configurable.
- Instrumentation must avoid unnecessary allocations where practical.
- Instrumentation must not serialize large payloads by default.
- Export must not occur synchronously on every record by default when a safer bounded processor is available.
- Performance claims require benchmarks.
- Exact performance budgets belong to implementation acceptance.

Memory-safety rules:

- Insight batches are bounded.
- Telemetry queues are bounded.
- Completed spans and records must become collectible.
- Exporters must release completed batches.
- Active context must detach.
- Diagnostic storage adapters must not retain live service graphs.
- Live request objects are not retained.
- Exception objects must not be retained indefinitely.
- Large request or response objects must not be retained.
- Repeated equivalent executions must not produce unexplained unbounded growth.
- Worker recycling remains permitted.
- Memory-safety claims require repeated-execution evidence.
- Performance and memory claims require evidence.

## 35. Configuration And Safe Defaults

Instrumentation configuration should be namespaced and validate Insight enabled state, Observe enabled state, enabled signals, sampling policy, propagators, resource identity, export mode, export endpoint, export timeout, queue capacity, batch limits, flush timeout, Insight retention, diagnostic categories, attribute limits, redaction policy, header allowlists, environment policy, criticality and duplicate-instrumentation policy.

Rules:

- Secrets must come from approved secret configuration.
- Invalid enabled configuration must fail clearly.
- Optional disabled integrations must not require their dependencies.
- Configuration changes affecting providers or exporters require controlled restart or rebuild.
- Modules and plugins must not replace application configuration silently.
- Exact configuration syntax is deferred.

Safe default direction:

- Generic instrumentation hooks available.
- Insight disabled unless deliberately enabled by application or development tooling.
- Observe disabled unless deliberately enabled.
- No exporter configured by Core.
- No production telemetry stored in application database.
- No payload capture.
- No authentication-header capture.
- No cookie capture.
- No SQL-binding capture.
- Bounded buffers.
- Bounded flush.
- Low-cardinality metrics.
- Standard semantic conventions where suitable.
- Explicit propagation.
- Redaction before storage or export.
- No telemetry criticality unless application explicitly declares it.
- Telemetry is non-critical unless explicitly configured otherwise.

## 36. Readiness And Health

Instrumentation health may distinguish:

- Disabled.
- Configured.
- Initializing.
- Ready.
- Degraded.
- Export unavailable.
- Failed.
- Shutting down.

Rules:

- Instrumentation health is distinct from application business health.
- Optional exporter failure does not normally make the application unready.
- An explicitly critical integration may affect readiness only through deliberate configuration.
- Health output must not expose credentials.
- Health checks must not export destructive test data.
- A process being alive does not prove telemetry is exporting.
- Telemetry exporting does not prove the application is healthy.
- Exact health APIs are deferred.

## 37. Shutdown

Application shutdown integration must:

- Stop accepting new diagnostic work.
- End remaining application-lifetime instrumentation where applicable.
- Detach active context if any remains.
- Freeze remaining detached data.
- Attempt bounded flush.
- Report dropped data safely.
- Shut down processors and exporters.
- Continue application shutdown even when optional telemetry flush fails.

Rules:

- Shutdown is not an unlimited export window.
- Exporters must respect deadlines.
- Shutdown failure must preserve the primary application shutdown failure.
- Repeated shutdown must be prevented or handled safely.
- No new business work may begin during telemetry shutdown.
- RFC 0004 remains authoritative for shutdown order.

## 38. Persistent-Worker Safety

Future implementation tests must prove:

- New context per execution.
- New Insight batch per execution.
- No active span inherited.
- No baggage inherited.
- No logging context inherited.
- No diagnostic record leakage.
- Export queue remains bounded.
- Failed export does not retain execution objects.
- Failed execution followed by successful execution is isolated.
- Authenticated followed by anonymous execution is isolated.
- Tenant A followed by Tenant B is isolated.
- Trace context A followed by no context is isolated.
- Reset failure quarantines the process.
- Telemetry-detachment failure quarantines the process.
- Detached export does not reactivate context.
- Memory growth remains understood and bounded.

RFC 0005 remains authoritative for quarantine.

## 39. Concurrency

Sequential execution per worker remains the safe baseline.

Concurrent instrumentation requires:

- Independent immutable contexts.
- Independent Insight batches.
- Thread-, fiber-, coroutine- or task-safe context handling as applicable.
- No global mutable current span shared across executions.
- No reset operation clearing another active execution.
- Concurrency-safe processors and exporters.
- Stress testing.

Rules:

- RFC 0007 does not require concurrent execution support.
- Instrumentation must not advertise concurrency beyond proven runtime guarantees.
- Exact concurrency implementation is deferred.

## 40. Testing Requirements

Future implementation tests must cover at minimum:

### Generic Instrumentation

Disabled consumers, Insight-only operation, Observe-only operation, Insight and Observe together, Core without optional packages, structured operation start and end, failure recording, instrumentation failure isolation, no lifecycle-order mutation, and no business logic in instrumentation callbacks.

### Insight

One batch per execution, batch closure, batch immutability, batch size limits, record limits, retention limits, redaction before persistence, disabled production default, storage failure isolation, no execution-object retention, and no cross-execution batch merging.

### Observe

Trace creation, upstream trace continuation, invalid-context rejection, context extraction, context injection, context detach, Baggage clear, sampling, low-cardinality metrics, log correlation, resource identity, semantic-convention mapping, export failure isolation, bounded queue, bounded retry, bounded flush, and dropped-data accounting.

### Lifecycle

Application boot instrumentation, execution instrumentation, termination observations, active span ending before scope closure, detached export after scope closure, reset instrumentation, shutdown flush, primary error preservation, and telemetry-detachment quarantine.

### Security

Secret redaction, authentication-header exclusion, cookie exclusion, SQL-binding exclusion, payload exclusion, Baggage allowlist, Baggage size limits, metric-cardinality policy enforcement, and diagnostic UI access control where implemented.

### Runtime And Bridge

HTTP execution, queue execution, CLI execution, embedded delegation, remote propagation, duplicate-span prevention, retry observation, uncertain Bridge outcome, and Runtime repeated-execution isolation.

RFC 0007 itself adds documentation-policy tests only.

## 41. Support And Compliance Claims

A package must not claim OpenTelemetry support merely because:

- It emits JSON.
- It creates IDs called trace IDs.
- It uses an OpenTelemetry package.
- It exports one span.
- It sends OTLP once.
- A Collector accepts its data.
- A vendor backend displays telemetry.

A support claim requires:

- Documented supported signals.
- Supported OpenTelemetry package versions.
- Supported propagators.
- Supported semantic conventions.
- Context-isolation tests.
- Export tests.
- Failure tests.
- Compatibility tests.
- Security and redaction tests.
- Persistent-worker tests where advertised.
- Documentation of known limitations.

Official conformance or compatibility wording must match actual evidence. OpenTelemetry support claims require evidence.

## 42. Architecture-Enforcement Direction

Future tooling should enforce:

- No Core dependency on Insight.
- No Core dependency on Observe.
- No Insight dependency on Observe.
- No Observe dependency on Insight.
- No OpenTelemetry SDK types in generic Core contracts.
- No host-framework types in generic instrumentation contracts.
- No runtime SDK types in generic instrumentation contracts.
- No secrets in known telemetry attributes.
- No forbidden metric dimensions.
- Stable custom convention documentation.
- Bounded configuration.
- Repeated-execution context tests.
- Documentation and changelog requirements.
- Optional dependency isolation.

Do not add enforcement tooling in this task.

## 43. Operational Documentation

A future Insight release should document:

- Enablement, supported environments, data categories collected, default exclusions, redaction, retention, storage limits, performance impact, UI access control, clearing diagnostic data and known limitations.

A future Observe release should document:

- Supported signals, supported propagators, supported semantic conventions, supported OpenTelemetry package versions, resource configuration, sampling, exporters, OTLP, Collector guidance, buffer limits, retry behaviour, flush deadlines, redaction, cardinality, performance, Runtime compatibility, Bridge compatibility and known limitations.

## 44. Explicit Non-Goals

This RFC does not implement generic instrumentation interfaces, Insight, an Insight store, an Insight UI, Observe, traces, metrics, logs, context propagation, baggage, sampling, semantic conventions, exporters, OTLP, database instrumentation, HTTP instrumentation or queue instrumentation.

It does not install OpenTelemetry packages, deploy a Collector, select a telemetry backend, modify Core, modify Runtime, modify Bridge, create packages, add dependencies, modify Composer metadata, modify PHP requirements or add CI.

It does not claim OpenTelemetry compliance. It does not begin Phase 2 implementation.

## 45. Consequences And Tradeoffs

### Positive Consequences

- Clear Insight and Observe separation.
- Optional observability packages.
- Vendor-neutral production telemetry.
- Better execution correlation.
- Safer persistent-worker instrumentation.
- Explicit context lifecycle.
- Stronger security and redaction.
- Lower cardinality risk.
- Bounded export behaviour.
- Better Bridge and Runtime integration.
- Testable support claims.
- Core remains minimal.
- Local diagnostics do not become a production backend.
- Production telemetry does not require local Insight storage.

### Negative Consequences

- More contracts and adapters.
- Additional performance overhead.
- Context management complexity.
- Semantic-convention maintenance.
- Export compatibility work.
- Redaction and classification burden.
- Cardinality review burden.
- Duplicate-instrumentation risk.
- Collector operations may add deployment complexity.
- Persistent-worker testing becomes more demanding.
- Supporting traces, metrics and logs increases maintenance.
- Insight UI and storage require separate substantial work.
- Vendor neutrality does not remove backend-specific operational differences.

These costs are accepted and must remain visible.

## 46. Alternatives Considered

### Put Diagnostics Directly In Core

Rejected because local diagnostic storage and interfaces are optional concerns.

### Make Observe Depend On Insight

Rejected because production telemetry must work without local diagnostics.

### Make Insight Depend On Observe

Rejected because local diagnostics must work without an OpenTelemetry SDK or exporter.

### Use Only Logs

Rejected because logs do not replace traces, metrics and execution context.

### Use Only Traces

Rejected because traces do not replace aggregate metrics or operational logs.

### Store All Telemetry In The Application Database

Rejected because it couples production observability to application storage and creates retention and performance risks.

### Require A Collector

Rejected as a Core requirement because direct or no-op configurations must remain possible.

A Collector remains a recommended optional production boundary in suitable deployments.

### Export Synchronously During Every Request

Rejected because exporter latency and failure must not dominate application handling.

### Use Unbounded In-Memory Queues

Rejected because exporter outages could exhaust worker memory.

### Capture Complete Request And Response Bodies

Rejected as the default because of security, privacy and volume risks.

### Copy All Baggage Into Attributes

Rejected because baggage is untrusted and may contain high-cardinality or sensitive values.

### Use User Or Tenant Identifiers As Metric Dimensions

Rejected because of cardinality, privacy and cross-tenant risk.

### Store The Active Span In A Process-Global Static

Rejected because it conflicts with RFC 0005 isolation and concurrency safety.

### Treat Telemetry Export As An Audit Ledger

Rejected because ordinary telemetry may be sampled, dropped, delayed or unavailable.

### Build A Proprietary Telemetry Backend First

Rejected because Observe is an integration layer, not a replacement for established backends.

## 47. Governance

- RFC 0007 is authoritative for Insight, generic instrumentation and OpenTelemetry architecture.
- RFC 0001 remains authoritative for product scope and Beta direction.
- RFC 0002 remains authoritative for package and public API boundaries.
- RFC 0003 remains authoritative for versioning and compatibility claims.
- RFC 0004 remains authoritative for application/module/plugin lifecycle.
- RFC 0005 remains authoritative for execution scope, reset, context isolation and quarantine.
- RFC 0006 remains authoritative for Bridge propagation and integration ownership.
- Insight and Observe implementations must preserve this separation.
- OpenTelemetry integration must implement accepted lifecycle ordering rather than redefine it.
- Material reversals require a superseding RFC.
- Custom semantic conventions require documented governance.
- OpenTelemetry support claims require evidence.
- Implemented status requires code, tests, dependency validation and runtime evidence.
- RFC 0007 completes the initial Phase 1 governance sequence when merged.
