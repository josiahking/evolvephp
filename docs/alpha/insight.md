# Insight operations

Evolve Insight is an optional local diagnostic surface for EvolvePHP 2 executions. It collects bounded Core observations and policy-accepted entries, projects completed batches into detached snapshots, and can persist them for guarded query and dashboard access. It is not an automatic profiler, authentication system, general audit ledger, or production telemetry exporter. EvolvePHP 2 is alpha software; production readiness is not claimed.

## Opt in and turn off

Applications compose Insight explicitly. A typical composition uses a caller-owned diagnostic SQLite connection, a bounded store, a capture policy, a pipeline, and the same execution correlation object for Core and any Insight infrastructure or HTTP diagnostics:

```php
$diagnosticPdo = new PDO('sqlite:/private/local/insight.sqlite');
$store = new SqliteDiagnosticBatchStore($diagnosticPdo, 100);
$capturePolicy = new DiagnosticCapturePolicy();
$pipeline = DiagnosticPipeline::storing($store, 100, 16, $capturePolicy);
$correlation = new ExecutionCorrelation();
$executions = new ExecutionOrchestrator($frozenServices, $pipeline, [$correlation]);
```

These classes are in `Evolve\Insight\Storage`, `Evolve\Insight\Capture`, `Evolve\Insight`, `Evolve\Insight\Infrastructure`, and `Evolve\Core\Execution`, respectively. The application supplies the frozen Core service registry, SQLite path, limits, and route composition. Installation does not automatically create a diagnostic database, attach watchers, wrap services, or register dashboard routes. To disable Insight completely, omit its pipeline, correlation, decorators, HTTP diagnostic middleware, store, query service, and dashboard routes from application composition. Ordinary Core execution needs none of them.

The SQLite store requires `pdo_sqlite` when selected. Keep its caller-owned connection and database separate from business storage; never point the diagnostic store at an application database. Construction creates the diagnostic schema on the supplied connection. Insight provides no default path or global enablement. Use a dedicated local database with application-controlled filesystem permissions. For clearing local diagnostic data, stop consumers of the diagnostic connection and release or unset the caller-owned PDO, or otherwise ensure no live connection remains. Then remove the dedicated SQLite database file and SQLite sidecar files as appropriate for the deployment. Insight provides no clear/delete-all API or time-based expiry. Never delete a shared business database to clear diagnostics.

## Capture, bounds, and sensitive data

`DiagnosticCapturePolicy` runs before an entry can enter a retained batch. By default it admits public and internal operational metadata, rejects other classifications, and never deliberately accepts raw secret or authentication attributes. `DefaultDiagnosticRedactor` suppresses secret/authentication data and replaces common sensitive operational names such as tokens, authorization, passwords, and cookies with `[REDACTED]`. Classification rejection, redaction, filtering, and sampling are separate controls. `DiagnosticCaptureFilter` disables exact categories or names for volume control. `DeterministicDiagnosticSampler` applies a stable execution-identifier hash at an integer percentage from 0 to 100; it controls volume, not access or confidentiality. The default 100 accepts every policy-eligible entry. Applications can pass an explicit filter or sampler into `DiagnosticCapturePolicy`.

`DiagnosticPipeline::storing($store, $observationLimit, $entryLimit, $capturePolicy)` retains accepted entries in arrival order up to the positive entry limit. Further policy-accepted entries increment **dropped diagnostic entries**; entries rejected by classification, filtering, or sampling do not count as capacity drops. Observation and diagnostic-entry dropped counts are separate and survive detached persistence. Capture attributes and strings are bounded, but accepted operational metadata can still be sensitive in context. Keep classifications conservative, inspect custom watcher output, and use an application access policy before reading persisted data.

The projector stores primitive snapshots, not live Core execution contexts, requests, responses, containers, scopes, or throwable objects. Capture removes values before projection, so query and dashboard rendering cannot reconstruct rejected or redacted candidate values. This boundary does not make unsafe application opt-ins safe: enabling business-sensitive capture or SQL inspection is an explicit application decision.

## Supported diagnostic sources

Applications may explicitly wrap accepted database, PSR-16 cache, queue, object-storage, and PSR-18 outbound HTTP interfaces with Insight diagnostic decorators. A `DiagnosticRecorder` shares the pipeline and `ExecutionCorrelation`; operations outside an attached execution create no infrastructure entry. Database SQL inspection requires both `DatabaseDiagnosticPolicy` and capture-policy opt-in. Parameter values and outbound HTTP bodies, headers, credentials, and full URIs are excluded from first-party diagnostics. Decorators are best effort and do not retry or change the underlying operation result.

For incoming HTTP, compose `HttpServerDiagnosticMiddleware` outside the routing handler and `MatchedRouteDiagnosticMiddleware` after routing has attached a route match. The outer middleware records bounded method, status, outcome, duration, and throwable type; the inner middleware supplies only the declared route template. Request path, query, route values, headers, cookies, bodies, and identities are excluded. `ExecutionLifecycleDiagnosticWatcher` derives supported failure and runtime observations from Core execution lifecycle events, including failed handlers, failed scope close, and quarantine required. Register observation watchers explicitly in `DiagnosticPipeline`; no global watcher discovery occurs. Unsupported plugin/module/component lifecycle, service-resolution, logging/event, worker-memory, tenant leak, and module-to-module invocation watchers are deferred.

## Persistence, query, and dashboard

Both first-party stores use count-bounded retention and prune the oldest successful unique batch before adding a new one at capacity. SQLite uses insertion sequence, not timestamps; time-based retention is unavailable. Duplicate identifiers are rejected before pruning. Completion writes a detached, versioned snapshot. Storage and local dashboard work add policy processing, projection, encoding, SQLite writes, reads, and HTML rendering when composed. Exact query filters may scan retained snapshots. No numeric overhead or throughput claim is made without measurements.

`DiagnosticQueryService` takes a `DiagnosticBatchReader` and an application-supplied `DiagnosticAccessPolicy`. The policy decides list and detail reads before storage access; Insight supplies no permissive default, user database, session integration, or role model. Queries use newest-first pages of 1 to 100 items, an exclusive retained-batch cursor, and exact execution-kind/category/name filters. Unknown or pruned cursors are rejected. Detail lookup returns a detached snapshot.

The native dashboard is also explicit:

```php
$queries = new DiagnosticQueryService($store, $applicationAccessPolicy);
$routes = DashboardRoutes::native(
    $queries,
    $responseFactory,
    $localizationContext,
    DashboardExposure::localDevelopment(),
);
```

`DiagnosticQueryService` is in `Evolve\Insight\Query`; `DashboardRoutes` and `DashboardExposure` are in `Evolve\Insight\Dashboard`. Add the returned routes to the application's HTTP router deliberately. The application provides a PSR response factory and i18n localization context. The native views and framework English catalog are bundled; no Twig or Blade adapter is needed. `DashboardRoutes::compose` accepts a caller-supplied renderer and translator. Pages have `Cache-Control: no-store`; list and detail remain under the same access policy. Invalid dashboard input is a bounded 400, denied access is 403, and an authorized missing detail is 404. Dashboard values are HTML escaped, but access to local diagnostics still requires application security controls.

Local/development composition requires the deliberate `DashboardExposure::localDevelopment()` choice and an application-supplied `DiagnosticAccessPolicy`. Production exposure requires the explicit `DashboardExposure::productionAuthorized()` choice. Neither option grants query access or infers safety from host, IP, environment variables, or request data. Remote or non-local access must be protected by application authentication and authorization. Insight does not provide authentication, users, sessions or roles. Dashboard access auditing is deferred because the framework has no accepted generic audit/event seam; Insight does not emit an access ledger.

## Sequential worker reuse and limits

With the same long-lived pipeline and `ExecutionCorrelation`, Core can run sequential failed and successful executions with distinct identifiers. Completed snapshots remain isolated, and correlation detaches after each execution. The acceptance tests also check that Insight does not retain a completed live `ExecutionContext`. This is evidence for sequential persistent-worker reuse only; it is not a claim of concurrent, fiber, or thread safety.

Insight is separate from **Evolve Observe** and OpenTelemetry. Insight keeps local, policy-filtered diagnostic evidence. It does not propagate traces, export OpenTelemetry data, aggregate across workers, diagnose named tenant leaks, or replace application logging and auditing.
