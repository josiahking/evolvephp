---
title: Evolve Observe
description: Explicit OpenTelemetry-oriented tracing and metrics composition.
sidebar_position: 2
status: experimental
package: evolvephp/observe
---

# Evolve Observe

Observe is an optional OpenTelemetry integration. The application supplies tracer, meter or logger providers and a resource, and owns exporters, transports, endpoints, credentials, sampling, retries and backend routing. Observe does not register global providers, read environment configuration, configure an exporter or certify production deployment.

Current explicit instrumentation covers Core executions; incoming HTTP and matched route templates; queue publication and Job execution; database operations; PSR-16 cache; object storage; and outbound PSR-18 HTTP. Applications install the corresponding wrappers and middleware around caller-owned implementations. Disabled composition delegates operations without creating telemetry.

Observe propagates W3C traceparent and conditional tracestate at supported incoming HTTP, queue/Job and outbound HTTP boundaries. Baggage remains opaque. Execution correlation detaches after completion for sequential isolation, but this is not a concurrent worker guarantee. HTTP SERVER spans can include the caller-owned URI path, so applications should consider identifiers in paths when choosing export policy. Metric labels use closed, bounded vocabularies rather than arbitrary paths, keys, SQL or payloads.

Observe excludes raw SQL and parameters, cache keys/values, storage content and keys, and outbound HTTP full URLs, bodies and credentials from its first-party telemetry. It does not constrain application-authored spans. Provider force-flush and shutdown are explicit; no shutdown hook or durable buffer is installed.

**Insight** retains policy-filtered local diagnostic batches. **Observe** composes telemetry for application-owned OpenTelemetry providers and exporters. Neither replaces logging or an audit ledger. Read the [Observe operations guide](https://github.com/josiahking/evolvephp/blob/2.x/docs/alpha/observe.md) for composition and limits.

## Explicit OpenTelemetry composition

With Observe available through Composer, an application can opt in and provide its own OpenTelemetry providers:

~~~php
<?php
require __DIR__ . '/vendor/autoload.php';

use Evolve\Observe\ObserveConfiguration;
use Evolve\Observe\OpenTelemetryCompositionFactory;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/** @var TracerProviderInterface $tracerProvider Application-owned provider */
$composition = (new OpenTelemetryCompositionFactory())->create(
    configuration: new ObserveConfiguration(enabled: true),
    tracerProvider: $tracerProvider,
);
~~~

Pass the composition to the specific Observe instrumentation you choose to install. The application owns providers, exporters, transports, timeouts, flush and shutdown, and any Collector deployment. Observe does not install global or automatic instrumentation. Insight provides Evolve-aware local diagnostics; Observe provides OpenTelemetry-oriented instrumentation and export composition.
