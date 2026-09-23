# EvolvePHP Observe

`evolvephp/observe`

OpenTelemetry composition and generic execution tracing foundation for EvolvePHP 2.

## Responsibility

Evolve Observe provides a small boundary for application-owned OpenTelemetry providers, explicit SDK resource identity and generic Core execution tracing. It consumes Core's generic lifecycle contracts through `ExecutionContextAttacher` and `ObservationSink`.

Observe is disabled by default. Disabled composition keeps all provider, resource and sampler references null, even when optional objects are supplied to the factory, and execution tracing creates no span or OpenTelemetry context state.

Enabled composition requires a caller-owned tracer provider and caller-owned `ResourceInfo` with a valid `service.name`. Observe validates that `service.name` is a string, non-empty, unpadded and at most 255 bytes. The supplied resource object is preserved by identity. Provider/resource consistency remains the application's composition responsibility because OpenTelemetry does not expose a portable provider-resource comparison API.

`ExecutionTraceInstrumentation` creates one generic INTERNAL `evolve.execution` span for each traced Core execution. The Evolve execution identifier remains framework lifecycle identity and is distinct from OpenTelemetry trace and span identity. `evolve.execution.id` is a trace-correlation attribute only and must not become a metric label or dimension when metrics are added later.

Custom Evolve telemetry vocabulary uses the `evolve.*` namespace through `EvolveSemanticConventions`. Stable OpenTelemetry semantic-convention constants are used where applicable, including `error.type` for handler failure classification.

Observe records bounded execution attributes and bounded lifecycle events only. Handler failures set span status ERROR and set `error.type` from Core's safe observation error type. Observe does not capture exception messages, stack traces, request data, user identifiers, tenant identifiers, session identifiers, raw URLs, SQL or arbitrary application values.

Applications own OpenTelemetry setup. They create providers, processors, readers, exporters, resources, sampling policy, shutdown behavior, backend configuration and any Collector deployment outside this package.

Observe does not register global OpenTelemetry state, call OpenTelemetry globals, read environment configuration, discover resources, create providers, create processors, create readers, create exporters, configure SDK builders, install automatic runtime wiring or require a Collector.

Observe depends on Core for generic lifecycle contracts. Observe has no dependency on Insight.

## Requirements

PHP `^8.4`

## Dependencies

- `evolvephp/core`
- `open-telemetry/api`
- `open-telemetry/sem-conv`

`evolvephp/core`, `open-telemetry/api` and `open-telemetry/sem-conv`; optional SDK resource and sampler typing is supported when applications install `open-telemetry/sdk`.

## Optional SDK Support

Applications that install `open-telemetry/sdk` may pass SDK `ResourceInfo` and `SamplerInterface` objects into Observe composition values. The SDK remains suggested rather than required by the published package.

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Current Limitations

This package does not implement HTTP W3C extraction or injection, baggage, HTTP SERVER spans, route-aware span names, HTTP attributes, queue spans, scheduled-job transport propagation, database spans, cache spans, storage spans, outbound HTTP-client spans, metrics, metric labels, structured log correlation, logger adapters, exporters, processors, readers, OTLP, Collector setup, retries, buffering, flush, shutdown, Bridge trace propagation, OpenTelemetry auto-instrumentation, global OpenTelemetry registration, environment interpretation, provider builders or resource detectors.

HTTP propagation and outer HTTP/server-span ownership remain deferred to a later roadmap slice. Metrics, structured-log correlation, exporters, Bridge propagation and infrastructure telemetry remain deferred.

## Licence

BSD-3-Clause. See `LICENSE.md`.
