# Observe operations

Evolve Observe is an optional OpenTelemetry integration for explicit EvolvePHP execution, HTTP, queue/job and infrastructure boundaries. It is experimental Alpha software. It does not install itself, replace application logging, configure an exporter, or certify a deployment for production use. Insight is a separate local diagnostic surface.

## Compose and disable

Applications construct their own OpenTelemetry tracer, meter or logger providers and an SDK resource with a valid `service.name`. Pass those objects to `OpenTelemetryComposition` when enabling Observe. At least one signal provider is required; tracer-only, meter-only and logger-only compositions are supported. The application owns provider/resource consistency, sampling, exporter and transport configuration, endpoints, credentials, Collector deployment, retry policy and backend routing.

```php
$composition = new OpenTelemetryComposition(
    enabled: true,
    tracerProvider: $applicationTracerProvider,
    meterProvider: $applicationMeterProvider,
    resource: $applicationResource,
);
```

The variables above are caller-created objects. For a disabled composition, use `OpenTelemetryComposition::disabled()` and omit Observe wrappers from application wiring when they are unnecessary. Disabled wrappers delegate application operations without creating telemetry. Observe does not register global OpenTelemetry providers, read environment configuration or discover resources.

## Wire the supported boundaries

| Boundary | Explicit Observe surface | Behavior |
| --- | --- | --- |
| Core execution | `ExecutionTraceInstrumentation`, `ExecutionMetricsInstrumentation` | One INTERNAL execution span when tracing is enabled; bounded execution duration, count, active, failure and quarantine metrics when metering is enabled. |
| Incoming HTTP | `HttpServerTraceInstrumentation`, `HttpServerMetricsInstrumentation`, `HttpRouteSpanMiddleware` | The outer wrappers cover the request-to-response operation; the route middleware enriches the existing SERVER span after a route match. |
| Queue publication and Job execution | `QueuePublisherInstrumentation`, `JobExecutionContextInstrumentation` | A PRODUCER span and W3C carrier on an immutable envelope; a CONSUMER span around a Job execution. |
| Database | `DatabaseConnectionInstrumentation` | CLIENT spans and metrics for execute, query and transaction through `DatabaseConnection`. |
| PSR-16 cache | `CacheInstrumentation` | INTERNAL spans and metrics for the eight PSR-16 methods. |
| Object storage | `ObjectStorageInstrumentation`, `ReadableObjectInstrumentation` | INTERNAL spans and metrics for put, open, delete, read and close. |
| Outbound PSR-18 HTTP | `HttpClientInstrumentation` | One CLIENT span and a derived request carrying W3C Trace Context through the existing client middleware seam. |

Install each decorator around the corresponding caller-owned contract implementation. Core, HTTP, Job, Queue Contracts, Database Contracts, Storage Contracts, HTTP Client and cache implementations remain OpenTelemetry-neutral. Observe does not depend on Insight or a concrete database, cache or storage adapter. It preserves application results and throwables; telemetry failures do not replace them. Database query iterables and cache bulk iterables are not consumed for telemetry, and storage put chunks remain lazy.

For incoming HTTP, place metrics outside tracing, start the SERVER span before `HttpKernel` runs, and put `HttpRouteSpanMiddleware` in the matched routing stack. Core execution tracing then attaches beneath the active SERVER span. The route middleware uses the declared route template for `http.route` and the final low-cardinality span name. Unmatched requests keep the bounded method-based span name.

SERVER tracing records the caller-owned PSR URI path as `url.path` and its scheme as `url.scheme` when present. A path can contain application-level identifiers; applications should design routes and export policy with that visibility in mind. Observe does not universally remove identifiers from URI paths. It excludes query strings, full URLs, request and response bodies, authorization, cookies and arbitrary headers. Concrete paths and URLs are never metric dimensions. Do not put secrets in URI paths when they must be absent from exported spans.

## Propagation and sequential isolation

Incoming HTTP tracing extracts W3C `traceparent` and conditional `tracestate`; it does not inject trace headers into responses. Queue publication injects those two fields into an immutable `MessageEnvelope`, replacing existing case variants; the Job consumer extracts them before Core execution. Outbound HTTP injects them into an immutable derived PSR-7 request. The outbound CLIENT span records bounded method and status telemetry but no URL attributes, including `url.path` or `url.scheme`; it also excludes host, query, userinfo and fragment. The derived request retains the caller-owned URI while Observe adds W3C context headers. These boundaries keep `baggage` opaque: Observe does not extract, interpret, activate, attach as attributes or propagate baggage on its own. Existing application-owned baggage values remain otherwise untouched by the wrappers.

A completed HTTP request, Job execution or Core execution detaches its active OpenTelemetry context. Successive executions on a persistent worker therefore do not inherit a stale active span or execution-log correlation. This is sequential reuse evidence, not a concurrent worker or runtime-adapter certification. Storage readers retain the parent context captured when opened, even if read or closed during a later current execution.

Remote Bridge may carry manually supplied `traceparent` and conditional `tracestate` on the outer invocation request. The receiving Observe HTTP SERVER wrapper validates and extracts that context. Configure one owner for the outer SERVER span when host auto-instrumentation is also present. Remote Bridge does not provide baggage propagation, shared sessions, automatic retries or distributed transactions.

## Logging, metrics and sensitive data

`ExecutionLogCorrelationInstrumentation` provides immutable snapshots of available trace/span and Core execution identity. Applications own their logger implementation, log provider, processors, exporters and log lifecycle. Observe does not create a logger, a PSR-3 adapter or application log records. Native OpenTelemetry logs obtain trace identity from the active context; only Evolve execution identity is supplied as Observe log attributes.

`MetricCardinalityPolicy` closes metric labels. Execution metrics use a bounded execution kind and, for outcome metrics, a bounded succeeded/failed outcome. HTTP server and client metrics use a bounded method or `_OTHER`. Queue metrics use producer or consumer role. Database, cache and storage metrics use their fixed operation vocabularies. Identifiers, route templates, concrete paths, URLs, query strings, keys, payloads, SQL, failure categories, status codes and arbitrary application values are not metric labels.

Database telemetry excludes raw SQL and parameters. A bounded database exception operation such as `transaction.commit` may refine the error span, while its failure metric remains the top-level `transaction` operation. Cache telemetry excludes keys, values and TTL contents and does not infer hit or miss. Storage telemetry excludes object bytes, keys, paths and provider data. Outbound HTTP excludes full URL, host, query, userinfo, fragment, bodies, authorization, cookies and arbitrary headers. Error spans use bounded metadata and safe error types rather than exception messages or stack traces. Review application-created spans and exporter policy separately; Observe cannot constrain telemetry added elsewhere.

## Export lifecycle and overhead

Applications may pass their own SDK exporters to `OpenTelemetryExportProcessingFactory`. `BatchExportConfiguration` bounds trace and log processor queue size, batch size and scheduling delay. Metric reading is explicit through an SDK reader. `ExporterFailureTracker` retains only fixed per-signal counts and safe error types, without payloads or throwable instances. `OpenTelemetryExportLifecycle` coordinates explicit force-flush and shutdown across caller-owned providers; it does not install shutdown hooks or flush automatically. Configure transport timeouts and retries in the application. Observe supplies no exporter transport or Collector.

The [Observe benchmark harness](../../benchmarks/README.md) compares equivalent prepared workloads in bare, disabled and enabled modes for execution, incoming HTTP, queue/job, database, cache, storage and outbound HTTP. Enabled benchmarks use a local SDK tracer without exporters. They measure instrumentation overhead only; they do not establish production throughput, exporter/backend performance, Collector latency or network behavior. Compare runs only under the documented controlled environment and matching fingerprints.

## Current limits

Observe requires explicit application wiring. It does not provide automatic instrumentation, global registration, environment-driven setup, a logger facade, scheduled-job transport propagation, worker/process/runtime metrics, broad outbound protocol coverage, telemetry storage, exporter transports, durable buffering or automatic flush. Concrete web runtime and persistent-worker concurrency guarantees remain outside this guide. See the [package reference](../../packages/observe/README.md) and [Alpha status](status-and-limitations.md) for the current package boundary.