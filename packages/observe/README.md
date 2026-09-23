# EvolvePHP Observe

`evolvephp/observe`

OpenTelemetry composition foundation for EvolvePHP 2.

## Responsibility

Evolve Observe provides a small composition-only boundary for application-owned OpenTelemetry providers and optional SDK values. It lets applications represent whether Observe composition is enabled, and when enabled, carry exact caller-supplied OpenTelemetry tracer, meter, logger, resource and sampler identities.

Observe is disabled by default. Disabled composition keeps all provider, resource and sampler references null, even when optional objects are supplied to the factory.

Applications own OpenTelemetry setup. They create providers, processors, readers, exporters, resources, sampling policy, shutdown behavior, backend configuration and any Collector deployment outside this package.

Observe does not register global OpenTelemetry state, call OpenTelemetry globals, read environment configuration, discover resources, create providers, create processors, create readers, create exporters, configure SDK builders, install automatic runtime wiring or require a Collector.

Observe has no dependency on Insight and no dependency on Core.

## Requirements

PHP `^8.4`

## Dependencies

- `open-telemetry/api`

`open-telemetry/api`; optional SDK resource and sampler typing is supported when applications install `open-telemetry/sdk`.

## Optional SDK Support

Applications that install `open-telemetry/sdk` may pass SDK `ResourceInfo` and `SamplerInterface` objects into Observe composition values. The SDK remains suggested rather than required by the published package.

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Current Limitations

This package does not create spans, metrics, logs, trace context, baggage, propagation, semantic-convention mappings, exporters, processors, readers, queues, retries, flush or shutdown hooks. It does not provide production-ready OpenTelemetry telemetry support or runtime integration.

## Licence

BSD-3-Clause. See `LICENSE.md`.
