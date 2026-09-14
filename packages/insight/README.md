# EvolvePHP Insight

`evolvephp/insight`

Diagnostic batch collection foundation for EvolvePHP 2.

## Responsibility

Evolve Insight consumes safe Core execution observations and collects them into immutable, bounded diagnostic batches. This package provides the storage-neutral `DiagnosticBatchSink` contract, immutable `DiagnosticBatch` values and `DiagnosticBatchCollector`, which implements Core's `ObservationSink` boundary.

Current bounded behavior:

- collection starts only after Core reports an execution start
- active collection state is isolated by Core execution identifier value
- observations for unknown or already completed executions are ignored
- retained observations are bounded per execution
- dropped observation counts are deterministic and non-negative
- completed executions are forgotten before the finalized batch is handed to the configured sink
- sink failures propagate to the existing Core instrumentation boundary

## Requirements

PHP `^8.4`

## Dependencies

- `evolvephp/core`

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Current Limitations

This package does not provide persistence, retention, pruning, redaction, filtering, sampling, dashboards, watchers, OpenTelemetry, Evolve Observe, trace propagation, runtime composition, automatic registration, storage adapters or production-ready diagnostics.

## Licence

BSD-3-Clause. See `LICENSE.md`.
