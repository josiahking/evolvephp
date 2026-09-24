# EvolvePHP Observe

`evolvephp/observe`

OpenTelemetry composition, generic execution tracing and explicit HTTP SERVER tracing foundation for EvolvePHP 2.

## Responsibility

Evolve Observe provides a small boundary for application-owned OpenTelemetry providers, explicit SDK resource identity, generic Core execution tracing and explicit HTTP SERVER tracing. It consumes Core's generic lifecycle contracts through `ExecutionContextAttacher` and `ObservationSink`, and it consumes Evolve HTTP routing state only for route-template span enrichment.

Observe is disabled by default. Disabled composition keeps all provider, resource and sampler references null, even when optional objects are supplied to the factory, and execution tracing creates no span or OpenTelemetry context state.

Enabled composition requires a caller-owned tracer provider and caller-owned `ResourceInfo` with a valid `service.name`. Observe validates that `service.name` is a string, non-empty, unpadded and at most 255 bytes. The supplied resource object is preserved by identity. Provider/resource consistency remains the application's composition responsibility because OpenTelemetry does not expose a portable provider-resource comparison API.

`ExecutionTraceInstrumentation` creates one generic INTERNAL `evolve.execution` span for each traced Core execution. The Evolve execution identifier remains framework lifecycle identity and is distinct from OpenTelemetry trace and span identity. `evolve.execution.id` is a trace-correlation attribute only and must not become a metric label or dimension when metrics are added later.

Custom Evolve telemetry vocabulary uses the `evolve.*` namespace through `EvolveSemanticConventions`. Stable OpenTelemetry semantic-convention constants are used where applicable, including `error.type` for handler failure classification.

Observe records bounded execution attributes and bounded lifecycle events only. Handler failures set span status ERROR and set `error.type` from Core's safe observation error type. Observe does not capture exception messages, stack traces, request data, user identifiers, tenant identifiers, session identifiers, raw URLs, SQL or arbitrary application values.

`HttpServerTraceInstrumentation` is an explicit outer PSR request-to-response wrapper. Applications call it around their own HTTP operation before `HttpKernel::handle()` runs. When enabled, it extracts inbound W3C Trace Context from `traceparent` and `tracestate`, starts and activates one SERVER span, attaches Observe-owned request-local span state, invokes the caller operation, records bounded stable HTTP and URL attributes, records the final response status or bounded throwable class, ends the span and detaches the OpenTelemetry scope. The existing `evolve.execution` span naturally becomes a child because Core attaches execution tracing while the SERVER span is active.

HTTP server span names are low-cardinality. Before routing, the span name is the bounded HTTP method token. Recognised methods use the same value for `http.request.method` and the span-name method token. Case-normalised recognised methods also record `http.request.method_original`. Unexpected methods record `http.request.method = _OTHER` and `http.request.method_original`, but use `HTTP` as the span-name method token so arbitrary raw method values never appear in span names.

SERVER spans record only the accepted bounded HTTP and URL attributes: `http.request.method`, optional `http.request.method_original`, `http.response.status_code`, `error.type` for safe status or throwable classification, `url.path` from the caller-owned PSR URI path and `url.scheme` when the caller-owned PSR URI exposes a scheme. Observe does not inspect `Forwarded`, `X-Forwarded-*` or `Host` headers to invent server-address policy. It does not record query strings, full URLs, request bodies, response bodies, authorization, cookies or arbitrary headers.

`HttpRouteSpanMiddleware` may be placed in the existing routed middleware stack after `RouteMatch` is attached; it reads the Evolve route template, sets `http.route` and updates the SERVER span name to `METHOD /route/{template}`. Route naming always uses the route template and never substitutes the concrete URI path. Unmatched and method-not-allowed requests without an authoritative `RouteMatch` remain method-only. The middleware never creates a second span, and telemetry enrichment failure cannot fail the downstream request.

Inbound baggage is deny-by-default. Observe does not extract the `baggage` header, activate baggage context, copy baggage into attributes, propagate baggage downstream or inject baggage. Observe also does not inject trace headers into responses. Outbound HTTP propagation remains outside this capability.

Applications own OpenTelemetry setup. They create providers, processors, readers, exporters, resources, sampling policy, shutdown behavior, backend configuration and any Collector deployment outside this package.

Observe does not register global OpenTelemetry state, call OpenTelemetry globals, read environment configuration, discover resources, create providers, create processors, create readers, create exporters, configure SDK builders, install automatic runtime wiring or require a Collector.

Observe depends on Core for generic lifecycle contracts and on HTTP for public route-template state. Core and HTTP themselves remain OpenTelemetry-neutral. Observe has no dependency on Insight.

## Requirements

PHP `^8.4`

## Dependencies

- `evolvephp/core`
- `evolvephp/http`
- `open-telemetry/api`
- `open-telemetry/sem-conv`
- `psr/http-message`
- `psr/http-server-handler`
- `psr/http-server-middleware`

`evolvephp/core`, `evolvephp/http`, `open-telemetry/api`, `open-telemetry/sem-conv`, `psr/http-message`, `psr/http-server-handler` and `psr/http-server-middleware`; optional SDK resource and sampler typing is supported when applications install `open-telemetry/sdk`.

## Optional SDK Support

Applications that install `open-telemetry/sdk` may pass SDK `ResourceInfo` and `SamplerInterface` objects into Observe composition values. The SDK remains suggested rather than required by the published package.

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Current Limitations

This package does not implement baggage, outbound HTTP-client spans, outbound HTTP injection, trace headers on responses, queue spans, scheduled-job transport propagation, database spans, cache spans, storage spans, metrics, metric labels, structured log correlation, logger adapters, exporters, processors, readers, OTLP, Collector setup, retries, buffering, flush, shutdown, Bridge trace propagation, OpenTelemetry auto-instrumentation, global OpenTelemetry registration, environment interpretation, provider builders or resource detectors.

Outbound propagation, metrics, structured-log correlation, exporters, Bridge propagation and infrastructure telemetry remain deferred.

## Licence

BSD-3-Clause. See `LICENSE.md`.
