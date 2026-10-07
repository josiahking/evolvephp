# EvolvePHP Observe

`evolvephp/observe`

See the [Observe operations](../../docs/alpha/observe.md) guide for application composition, telemetry boundaries and export ownership.

OpenTelemetry composition, execution, HTTP and infrastructure tracing, metrics, log correlation and bounded export-processing foundation for EvolvePHP 2.

## Operational ownership

Observe owns the composition boundary, local execution and export lifecycle surfaces, and explicit database, cache, storage, outbound HTTP client, queue producer and Job consumer telemetry. Applications remain responsible for provider setup, exporters, transports, Collector wiring, backend routing, retry policy and transport timing policy.

## Responsibility

Evolve Observe provides a small boundary for application-owned OpenTelemetry providers, explicit SDK resource identity, generic Core execution tracing and metrics, explicit HTTP SERVER tracing and bounded metrics, structured-log correlation snapshots and optional bounded SDK export-processing integration. It consumes Core's generic lifecycle contracts through `ExecutionContextAttacher` and `ObservationSink`, and it consumes Evolve HTTP routing state only for route-template span enrichment.

Observe is disabled by default. Disabled composition keeps all provider, resource and sampler references null, even when optional objects are supplied to the factory, and execution tracing creates no span or OpenTelemetry context state.

Enabled composition requires caller-owned `ResourceInfo` with a valid `service.name` and at least one caller-owned signal provider: tracer, meter or logger. Tracer-only, meter-only, logger-only and multi-provider compositions are valid. A sampler is accepted only when a tracer provider is present. Observe validates that `service.name` is a string, non-empty, unpadded and at most 255 bytes. Supplied provider, resource and sampler objects are preserved by identity. Provider/resource consistency remains the application's composition responsibility because OpenTelemetry does not expose a portable provider-resource comparison API.

`ExecutionTraceInstrumentation` creates one generic INTERNAL `evolve.execution` span for each traced Core execution. It is inert when Observe is disabled or when enabled composition has no tracer provider. The Evolve execution identifier remains framework lifecycle identity and is distinct from OpenTelemetry trace and span identity. `evolve.execution.id` is a trace-correlation attribute only and never a metric label or dimension.

Custom Evolve telemetry vocabulary uses the `evolve.*` namespace through `EvolveSemanticConventions`. Stable OpenTelemetry semantic-convention constants are used where applicable, including `error.type` for handler failure classification.

Observe records bounded execution attributes and bounded lifecycle events only. Handler failures set span status ERROR and set `error.type` from Core's safe observation error type. Observe does not capture exception messages, stack traces, request data, user identifiers, tenant identifiers, session identifiers, raw URLs, SQL or arbitrary application values.

`HttpServerTraceInstrumentation` is an explicit outer PSR request-to-response wrapper. Applications call it around their own HTTP operation before `HttpKernel::handle()` runs. When enabled, it extracts inbound W3C Trace Context from `traceparent` and `tracestate`, starts and activates one SERVER span, attaches Observe-owned request-local span state, invokes the caller operation, records bounded stable HTTP and URL attributes, records the final response status or bounded throwable class, ends the span and detaches the OpenTelemetry scope. The existing `evolve.execution` span naturally becomes a child because Core attaches execution tracing while the SERVER span is active.

HTTP server span names are low-cardinality. Before routing, the span name is the bounded HTTP method token. Recognised methods use the same value for `http.request.method` and the span-name method token. Case-normalised recognised methods also record `http.request.method_original`. Unexpected methods record `http.request.method = _OTHER` and `http.request.method_original`, but use `HTTP` as the span-name method token so arbitrary raw method values never appear in span names.

SERVER spans record only the accepted bounded HTTP and URL attributes: `http.request.method`, optional `http.request.method_original`, `http.response.status_code`, `error.type` for safe status or throwable classification, `url.path` from the caller-owned PSR URI path and `url.scheme` when the caller-owned PSR URI exposes a scheme. Observe does not inspect `Forwarded`, `X-Forwarded-*` or `Host` headers to invent server-address policy. The caller-owned URI path can contain application-level identifiers; applications should account for that when designing routes and exporter policy. The declared route template supplies low-cardinality `http.route` and final span naming, while concrete paths and URLs are excluded from metric dimensions. Observe does not record query strings, full URLs, request bodies, response bodies, authorization, cookies or arbitrary headers.

`HttpRouteSpanMiddleware` may be placed in the existing routed middleware stack after `RouteMatch` is attached; it reads the Evolve route template, sets `http.route` and updates the SERVER span name to `METHOD /route/{template}`. Route naming always uses the route template and never substitutes the concrete URI path. Unmatched and method-not-allowed requests without an authoritative `RouteMatch` remain method-only. The middleware never creates a second span, and telemetry enrichment failure cannot fail the downstream request.

Inbound baggage is deny-by-default. Observe does not extract the `baggage` header, activate baggage context, copy baggage into attributes, propagate baggage downstream or inject baggage. Observe also does not inject trace headers into responses. Outbound HTTP propagation is provided separately by `HttpClientInstrumentation`.

For Remote Bridge deployments, Bridge clients may project an invocation's manual `traceparent` and conditional `tracestate` carrier onto the outer HTTP request. `HttpServerTraceInstrumentation` remains the receiving boundary that extracts and semantically validates that W3C context before delegated execution. Exactly one component must own the outer Remote Bridge HTTP SERVER span; applications using framework or host OpenTelemetry auto-instrumentation for that server request must not also wrap the same request with Evolve's explicit HTTP server tracing.

`ExecutionMetricsInstrumentation` consumes Core execution observations without modifying Core. It is inert when Observe is disabled or when enabled composition has no meter provider; no meter lookup, instrument creation or per-execution metric state is allocated in those modes. A tracer-only composition therefore produces no metrics. A meter-only composition produces metrics while trace instrumentation remains inert.

Execution metrics use instrumentation scope `evolvephp/observe` and these instruments:

- `evolve.execution.duration`: histogram, unit `s`
- `evolve.execution.count`: counter, unit `{execution}`
- `evolve.execution.active`: up/down counter, unit `{execution}`
- `evolve.execution.failures`: counter, unit `{execution}`
- `evolve.execution.quarantines`: counter, unit `{execution}`

`HttpServerMetricsInstrumentation` is an explicit PSR request-to-response wrapper with request-local Observe-owned metric state. It is inert when Observe is disabled or when no meter provider is present. It records metrics with instrumentation scope `evolvephp/observe` and these instruments:

- `http.server.request.duration`: histogram, unit `s`
- `evolve.http.server.request.count`: counter, unit `{request}`
- `evolve.http.server.active_requests`: up/down counter, unit `{request}`
- `evolve.http.server.request.failures`: counter, unit `{request}`

The HTTP duration metric uses the stable OpenTelemetry semantic-convention name. The active request metric intentionally uses an Evolve-owned name instead of the incubating OpenTelemetry `http.server.active_requests` contract.

All metrics use the closed `MetricCardinalityPolicy`; instrumentation does not expose an API for arbitrary extra metric labels. Execution metric dimensions are limited to `evolve.execution.kind` and, where applicable, `evolve.execution.outcome`. The accepted execution kind values are `http_request`, `queue_message`, `scheduled_job`, `cli_command` and `worker_task`; outcomes are `succeeded` and `failed`. HTTP metrics use only `http.request.method`; recognised methods are canonical uppercase values, while every other method maps to `_OTHER`. Raw/original HTTP methods are never metric attributes.

Metric dimensions must not include `evolve.execution.id`, trace IDs, span IDs, raw HTTP methods, `http.route`, concrete paths, URLs, query strings, HTTP status codes, throwable classes, `error.type`, user, tenant or session identity, authorization, cookies, arbitrary headers, request or response bodies, SQL or arbitrary application-provided values.

The structural cardinality ceilings are: 10 series for `evolve.execution.duration` and `evolve.execution.count`, 5 series for `evolve.execution.active`, `evolve.execution.failures` and `evolve.execution.quarantines`, and 10 series for each HTTP metric. Observe does not maintain a runtime cache of every series ever seen to enforce those numbers.

`ExecutionLogCorrelationInstrumentation` is an optional execution-context attacher and `LogCorrelationProvider`. When Observe is enabled, it stores the current Evolve execution ID and execution kind in the active OpenTelemetry `Context` and returns immutable `LogCorrelation` snapshots containing any currently available trace ID, span ID, W3C trace flags, execution ID and execution kind. Disabled Observe returns empty snapshots and creates no context scope.

`LogCorrelation::structuredFields()` is for non-OTLP structured logging and may return only `trace_id`, `span_id`, `trace_flags`, `evolve.execution.id` and `evolve.execution.kind` when those values are present. `LogCorrelation::openTelemetryAttributes()` returns only `evolve.execution.id` and `evolve.execution.kind`; native OpenTelemetry logs get trace ID, span ID and trace flags from the active OpenTelemetry context as top-level log-record fields.

Observe does not create or decorate loggers. Applications own their logger provider, processors, exporters, resources, flushing, shutdown and backend configuration. The correlation boundary does not inspect baggage, tracestate, user identity, tenant identity, session identity, auth state, cookies, arbitrary headers, request or response bodies, URLs, query strings, SQL, exception messages, stack traces or arbitrary application logging context.

`OpenTelemetryExportProcessingFactory` composes caller-owned SDK exporters into real SDK batch processors and metric readers. For traces and logs, `BatchExportConfiguration` supplies finite maximum queue size, finite maximum export batch size, finite scheduling delay and explicit auto-flush enablement. It does not expose an Evolve hard export timeout, retry count, endpoint, header, credential, TLS, HTTP client, gRPC client or transport option. For metrics, the factory creates a real SDK `ExportingReader`; metric export is explicit through SDK collect, force-flush or shutdown behavior and is not autonomously periodic.

`ExporterFailureTracker` is a fixed trace/metric/log health surface for exporter calls. It records only bounded integer failure counts and the most recent safe error type per signal: a throwable class name or `_OTHER` when an exporter reports failure without a throwable. It never retains throwable instances, messages, stack traces, exported payloads, endpoints, headers, credentials, telemetry attributes or application context.

`OpenTelemetryExportLifecycle` coordinates explicit force-flush and shutdown across caller-owned SDK trace, metric and log providers. Missing providers remain absent in the immutable `ExportLifecycleResult`. One signal returning `false` or throwing is recorded for that signal and does not prevent remaining supplied signals from being invoked. Construction does not register shutdown hooks or perform automatic flushing.

When callers pass a meter provider to the processing factory, Observe forwards it to SDK processors/readers where supported so the OpenTelemetry SDK may emit its own self-telemetry. Those upstream/incubating metric names are not stable `evolve.*` contracts, and Observe does not duplicate SDK queue-capacity or queue-size metrics.

Applications own OpenTelemetry setup. They create providers, exporters, transports, resources, sampling policy, transport request timeouts, retry policy, shutdown behavior, backend configuration and any Collector deployment outside this package. Production deployments should prefer sending OTLP to an OpenTelemetry Collector that owns backend routing, buffering and exporter policy. With OpenTelemetry PHP SDK 1.15.x, the SDK batch processor `exportTimeoutMillis` constructor argument and stock PSR transport do not provide a portable hard wall-clock flush deadline; applications must configure finite caller-owned transport timeout and retry policy.

Observe does not register global OpenTelemetry state, call OpenTelemetry globals, read environment configuration, discover resources, create providers, create exporters, configure SDK builders, install automatic runtime wiring, install shutdown hooks, create retry queues, manage Collector processes or provide backend routing/fan-out. It does not own endpoints, authorization headers, API keys, TLS certificates, HTTP clients, gRPC clients or exporter transport selection, and infrastructure decorators do not install themselves or configure owning adapters.

Observe depends on Core for generic lifecycle contracts, HTTP for public route-template state, Job for the execution-context seam, QueueContracts for publisher and envelope contracts, DatabaseContracts for SQL operations, StorageContracts for object operations, HttpClient for the outbound middleware seam, PSR-16 for cache and PSR-18 for outbound client delegation. The owning packages remain OpenTelemetry-neutral. Observe has no dependency on Insight or any concrete database, cache or storage adapter.

## Infrastructure telemetry

The decorators are explicitly installed around application-owned public seams. They remain inert when Observe is disabled. With an enabled tracer provider they create one span per operation; with an enabled meter provider they record duration, count and thrown-operation failures. They use instrumentation scope `evolvephp/observe`. The timer surrounds the delegated operation and excludes telemetry setup and cleanup. Telemetry failures do not replace application results or throwables.

`DatabaseConnectionInstrumentation` decorates `DatabaseConnection::execute()`, `query()` and `transaction()` with CLIENT spans. It invokes the owning connection's transaction method and supplies an Observe-decorated active connection to the application callback. Query results retain their iterable identity and laziness; Observe never iterates rows. The operation name is span-only. Bounded database exception category, SQLSTATE and driver metadata may appear only on an error span. For a database exception, the error span operation may be refined to the exception operation, including transaction.begin, transaction.commit or transaction.rollback. Database metrics retain only the top-level execute, query or transaction operation. Raw SQL, parameter names and values, connection strings, credentials and vendor codes are excluded.

`CacheInstrumentation` decorates all eight PSR-16 operations with INTERNAL spans. It preserves iterable inputs and outputs without enumerating them for telemetry. Cache keys, values and TTL contents are excluded. A `get()` result does not yield a reliable hit or miss classification, so Observe emits no inferred hit/miss metric.

`ObjectStorageInstrumentation` decorates `put()`, `open()` and `delete()`; a non-null `open()` result is wrapped by `ReadableObjectInstrumentation` for `read()` and `close()`. These are INTERNAL spans. Put chunks remain lazy and object bytes, storage keys, roots, paths, provider endpoints and arbitrary provider metadata are excluded. The reader captures the OpenTelemetry parent context at `open()`; later reads and closure use that origin even when another execution is current. These decorators do not activate an OpenTelemetry scope. Storage exception category is span-only.

`HttpClientInstrumentation` implements the existing `ClientMiddleware` seam. It creates one CLIENT span from the current OpenTelemetry context and sends one immutable derived PSR-7 request. It replaces existing `traceparent` and `tracestate` case-insensitively, then injects W3C `traceparent` and conditional `tracestate` under canonical keys. Baggage is opaque and unchanged. It preserves the exact response or thrown error. Returned HTTP 4xx and 5xx responses are normal responses; only thrown client operations increment the failure metric. The span may record a bounded method and response status, but never the full URL, host, userinfo, query, fragment, bodies, authorization, cookies, credentials or arbitrary headers.

Each family has one closed metric dimension. Database uses `evolve.database.operation` with `execute`, `query` or `transaction`; cache uses `evolve.cache.operation` with the eight PSR-16 method names; storage uses `evolve.storage.operation` with `put`, `open`, `read`, `delete` or `close`; outbound HTTP uses bounded `http.request.method` with known methods or `_OTHER`. The structural series budgets are 3, 8, 5 and 10 respectively. No identifier, failure category, driver, status, storage key, cache key or URL is a metric label.

| Family | Duration histogram (`s`) | Count counter | Failure counter |
| --- | --- | --- | --- |
| Database | `evolve.database.operation.duration` | `evolve.database.operation.count` | `evolve.database.operation.failures` |
| Cache | `evolve.cache.operation.duration` | `evolve.cache.operation.count` | `evolve.cache.operation.failures` |
| Storage | `evolve.storage.operation.duration` | `evolve.storage.operation.count` | `evolve.storage.operation.failures` |
| Outbound HTTP | `evolve.http.client.request.duration` | `evolve.http.client.request.count` | `evolve.http.client.request.failures` |
## Queue producer and Job consumer telemetry

`QueuePublisherInstrumentation` decorates the existing `QueuePublisher` contract. With tracing enabled it starts one fixed-name PRODUCER span, `evolve.queue.produce`, and injects W3C `traceparent` and conditional `tracestate` into a new immutable `MessageEnvelope`. Existing case variants of those two keys are replaced with canonical lowercase keys. The payload and all other metadata remain opaque and unchanged. The original envelope is passed through unchanged when Observe is disabled. Producer tracing uses an explicit context and does not activate a producer scope.

`JobExecutionContextInstrumentation` implements the Job execution-context seam. It extracts only W3C Trace Context, starts one fixed-name CONSUMER span, `evolve.queue.consume`, and activates it around the one-shot Core execution. The existing `evolve.execution` span and metrics remain Core-owned; that span becomes a child of the consumer span. A message without valid remote context starts a fresh trace. Each invocation has its own context and metric state, including in persistent workers. Observe does not change Job settlement.

Both boundaries record `evolve.queue.message.duration` (histogram, unit `s`), `evolve.queue.message.count` (counter, unit `{message}`) and `evolve.queue.message.failures` (counter, unit `{message}`). Every queue metric has exactly one dimension, `evolve.queue.role`, with only `producer` or `consumer` as its value. The structural budget is two series per queue metric. Span errors use only a safe throwable class, and producer queue failures may add the bounded queue failure category. Neither spans nor metrics record queue names, payloads, arbitrary metadata, exception messages or stacks.

Observe does not extract, inject or interpret baggage. A `baggage` metadata entry remains opaque application metadata. Applications own OpenTelemetry providers, resources, exporters and transport configuration. Telemetry setup, recording and span-ending failures do not replace publication or Core results. A failed consumer context detach is an isolation failure; it is surfaced to `JobRunner`, which quarantines and skips settlement. If Core start and context detach both fail, the detach failure takes precedence so isolation safety is visible.

## Requirements

PHP `^8.4`

## Dependencies

- `evolvephp/core`
- `evolvephp/database-contracts`
- `evolvephp/http`
- `evolvephp/http-client`
- `evolvephp/job`
- `evolvephp/queue-contracts`
- `evolvephp/storage-contracts`
- `open-telemetry/api`
- `open-telemetry/sem-conv`
- `psr/http-client`
- `psr/http-message`
- `psr/http-server-handler`
- `psr/http-server-middleware`
- `psr/simple-cache`

`evolvephp/core`, `evolvephp/database-contracts`, `evolvephp/http`, `evolvephp/http-client`, `evolvephp/job`, `evolvephp/queue-contracts`, `evolvephp/storage-contracts`, `open-telemetry/api`, `open-telemetry/sem-conv`, `psr/http-client`, `psr/http-message`, `psr/http-server-handler`, `psr/http-server-middleware` and `psr/simple-cache`; optional SDK resource, sampler, export-processing, reader and lifecycle integration is supported when applications install `open-telemetry/sdk`.

## Optional SDK Support

Applications that install `open-telemetry/sdk` may pass SDK `ResourceInfo` and `SamplerInterface` objects into Observe composition values, compose caller-owned exporters through Observe's bounded SDK processor/reader factory and coordinate explicit SDK provider force-flush or shutdown through the lifecycle helper. The SDK remains suggested rather than required by the published package. OTLP exporters and transports remain application-owned and are not a package dependency.

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Current Limitations

This package does not implement baggage, trace headers on responses, scheduled-job transport propagation, worker/process/runtime metrics, an `EvolveLogger`, logger facades, PSR-3 adapters, Monolog adapters, logger decorators, mandatory OpenTelemetry logging, Evolve-owned exporters, endpoint configuration, transport configuration, Collector setup, retries, durable buffering, automatic flush, shutdown hooks, OpenTelemetry auto-instrumentation, global OpenTelemetry registration, environment interpretation, provider builders or resource detectors.

Other outbound protocols, logger adapters and telemetry drop-health metrics beyond the narrow exporter failure tracker are outside this package.

## Licence

BSD-3-Clause. See `LICENSE.md`.
