# EvolvePHP Insight

`evolvephp/insight`

Diagnostic batch collection and storage-projection foundation for EvolvePHP 2.

## Responsibility

Evolve Insight consumes safe Core execution observations and collects them into immutable, bounded diagnostic batches. This package provides the storage-neutral `DiagnosticBatchSink` contract, immutable `DiagnosticBatch` values, `DiagnosticBatchCollector`, which implements Core's `ObservationSink` boundary, and an explicit `DiagnosticPipeline` composition helper for applications that opt in to collection plus persistence.

Insight also provides a storage-neutral projection boundary for finalized diagnostic batches. `DiagnosticBatchProjector` detaches a `DiagnosticBatch` into a primitive-only `DiagnosticBatchSnapshot` made of string-backed execution identity, execution kind, ordered `DiagnosticObservationSnapshot` values, ordered accepted diagnostic-entry snapshots and separate dropped observation and diagnostic-entry counts. Snapshots do not retain Core `Observation`, execution identifier, diagnostic-entry objects, diagnostic-attribute objects, request, response, container, throwable or execution-scope objects.

Insight includes a detached diagnostic capture-policy foundation for future rich diagnostic sources. `DiagnosticEntry` and `DiagnosticAttribute` represent bounded primitive-only diagnostic data: execution identifier value, diagnostic category, diagnostic name and ordered attributes whose values are limited to `string`, `int`, `float`, `bool` or `null`. Attribute names, entry identifiers, categories, names and string values are bounded, and non-finite floats, arrays, objects, resources and callables are not accepted.

Current bounded behavior:

- collection starts only after Core reports an execution start
- active collection state is isolated by Core execution identifier value
- observations for unknown or already completed executions are ignored
- candidate diagnostic entries are retained only when their execution identifier exactly matches an active execution
- diagnostic entries for unknown or already completed executions are ignored and never start collection
- candidate diagnostic entries pass through `DiagnosticCapturePolicy` before becoming retainable batch data
- retained diagnostic entries are bounded per execution and keep accepted arrival order
- retained observations are bounded per execution
- dropped observation counts are deterministic and non-negative
- dropped diagnostic-entry counts are deterministic, non-negative and count only policy-accepted entries that exceeded the per-execution entry bound
- completed executions are forgotten before the finalized batch is handed to the configured sink
- sink failures propagate to the existing Core instrumentation boundary

Current watcher behavior:

- `ObservationDiagnosticWatcher` is an explicit extension point for deriving candidate diagnostic entries from one existing Core `Observation`
- `DiagnosticPipeline` accepts zero or more configured observation watchers and validates them during composition
- watcher registration is caller-owned; Insight does not discover watchers, mutate Core state, read environment configuration or install global/static runtime registration
- watcher order is preserved, and each watcher's produced-entry order is preserved
- `ExecutionStarted` observations open the execution batch before watcher candidates are captured
- `ExecutionCompleted` watcher candidates are captured before the completion observation finalizes and forgets the execution
- watcher-produced entries must carry the exact triggering execution identifier value and are rejected before capture when they do not
- watcher candidates flow through the same `DiagnosticBatchCollector::capture()` path as explicitly submitted diagnostic entries, including filtering, redaction, sampling, retained-entry bounds and dropped-entry accounting
- watcher failures during completion still allow the collector to observe completion and finalize the execution before the failure is rethrown through the normal observation sink call path

Current first-party abnormal diagnostics:

- failed `HandlerCompleted` observations produce `evolve.execution` / `handler-failed`
- failed `ScopeCloseCompleted` observations produce `evolve.runtime` / `scope-close-failed`
- `QuarantineRequired` observations produce `evolve.runtime` / `quarantine-required`

These diagnostics use bounded primitive operational attributes already present on the triggering observation, including execution kind, error type when available and normalized process-reuse decision when available. They do not include exception messages, stack traces, request or response values, route parameters, headers, cookies, payloads, containers, scopes, service instances, tenant identifiers, timestamps, durations or arbitrary application values.

Current storage behavior:

- `DiagnosticBatchStore` defines minimal save, exact execution-identifier lookup and newest-first bounded reads
- `DiagnosticBatchReader` defines the separate read/query boundary for exact detail lookup and bounded cursor queries without adding query methods to the persistence contract
- `InMemoryDiagnosticBatchStore` keeps snapshots in insertion order, requires an explicit positive stored-batch count and prunes oldest retained snapshots first
- `SqliteDiagnosticBatchStore` provides an optional persistent local-development adapter for caller-supplied SQLite `PDO` connections, requires an explicit positive stored-batch count and prunes by SQLite sequence order
- `DiagnosticBatchSnapshotCodec` stores snapshots as a versioned primitive JSON payload, writes the current expanded schema with diagnostic entries, continues to read legacy observation-only version 1 payloads, and rejects malformed, unsupported or unexpected persisted data during reads
- duplicate execution identifiers are rejected and never replace the original snapshot
- `StoringDiagnosticBatchSink` projects accepted batches and saves the detached snapshot through a configured store
- storage remains optional and unwired; installing Insight does not create storage automatically

Both first-party stores use explicit count-bounded retention. Callers configure a positive maximum stored-batch count, and successful unique saves leave no more than that many retained diagnostic batch snapshots. Pruning is deterministic oldest-first insertion order; SQLite uses its monotonic sequence column as the insertion-order authority. Duplicate execution identifiers are rejected before pruning, so a duplicate save at capacity does not evict or mutate retained snapshots. Time-based retention is not provided.

The SQLite store creates its diagnostic table only when explicitly constructed with a SQLite `PDO`; it does not discover a default path, read application database configuration or automatically use application storage. SQLite reads use deterministic insertion-order sequence values for newest-first results, not diagnostic timestamps. Corrupt stored payloads are not decoded during construction, but the affected `find()` or `latest()` read fails explicitly.

`pdo_sqlite` is a runtime requirement only for applications that explicitly use `SqliteDiagnosticBatchStore`; it is not required for installing or using the non-SQLite Insight functionality.

Current query and access behavior:

- `DiagnosticBatchQuery` supports a page size from 1 to 100, an optional exclusive cursor and optional exact filters for persisted execution kind, diagnostic category and diagnostic name
- cursors are execution identifiers from the final item of the previous page, and the next page starts strictly older than that retained batch
- unknown or pruned cursors fail closed instead of restarting from newest or returning a misleading empty page
- query results are newest-first according to the store's insertion authority: in-memory insertion order or SQLite sequence
- `DiagnosticBatchPage` contains detached `DiagnosticBatchSummary` items and a next cursor only when another older matching result exists
- summaries expose only execution identifier, execution kind, retained observation count, retained diagnostic-entry count and dropped counts
- category and name filters are exact, and when both are supplied they must match the same diagnostic entry snapshot
- exact detail lookup returns the detached persisted `DiagnosticBatchSnapshot`
- `DiagnosticQueryService` requires an application-supplied `DiagnosticAccessPolicy` before list or detail reads and performs authorization before delegating to storage
- Insight does not provide a default access policy, discover users, define roles, authenticate requests, inspect sessions, infer localhost or automatically allow development access

Current capture-policy behavior:

- `DiagnosticDataClassification` provides explicit machine-readable classifications for public operational metadata, internal operational metadata, personal data, authentication data, secret data, business-sensitive payloads and regulated data
- `DiagnosticCapturePolicy` accepts public and internal operational metadata by default; personal, business-sensitive and regulated data are excluded unless explicitly enabled by application code
- secret and authentication data are never deliberately accepted raw
- `DefaultDiagnosticRedactor` deterministically suppresses secret and authentication attributes, and replaces common sensitive operational machine names such as authorization, password, cookies, tokens, API keys, secrets and session identifiers with `[REDACTED]`
- `DiagnosticCaptureFilter` supports exact category and diagnostic-name disabling for volume control only
- `DeterministicDiagnosticSampler` supports integer percentage sampling from 0 to 100 using a stable hash of the execution identifier
- sampling controls diagnostic volume only; it is not authorization, authentication, security enforcement, redaction, legal retention or an error-retention guarantee
- accepted attributes keep their original order, use first-accepted-wins duplicate-name handling and are capped by a deterministic retained-attribute limit

The capture-policy foundation does not add rich HTTP, database, cache, log, event, queue or other watchers. Candidate capture entries can be submitted explicitly to `DiagnosticBatchCollector` or `DiagnosticPipeline`, and configured observation watchers can derive candidates from existing Core execution observations. Policy-accepted entries become part of the same finalized execution batch as Core observations, are projected into detached diagnostic-entry snapshots and can persist through the configured projector, snapshot codec and store. This integration remains opt-in and does not add automatic runtime wiring.

## Requirements

PHP `^8.4`

## Dependencies

- `evolvephp/core`

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Current Limitations

This package does not provide time-based retention, timestamp or range queries, rich HTTP/database/cache/log/event/queue/plugin/module/service-resolution/worker-memory/tenant diagnostic watchers, native dashboards, routes, UI rendering, authentication, user or role management, OpenTelemetry, Evolve Observe, trace propagation, runtime composition, automatic registration, application database integration, production telemetry export or production-ready diagnostics. The current scope-close and quarantine diagnostics are generic runtime signals only; they do not prove a specific request-scope leak, tenant leak or memory leak.

## Licence

BSD-3-Clause. See `LICENSE.md`.
