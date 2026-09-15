# EvolvePHP Insight

`evolvephp/insight`

Diagnostic batch collection and storage-projection foundation for EvolvePHP 2.

## Responsibility

Evolve Insight consumes safe Core execution observations and collects them into immutable, bounded diagnostic batches. This package provides the storage-neutral `DiagnosticBatchSink` contract, immutable `DiagnosticBatch` values and `DiagnosticBatchCollector`, which implements Core's `ObservationSink` boundary.

Insight also provides a storage-neutral projection boundary for finalized diagnostic batches. `DiagnosticBatchProjector` detaches a `DiagnosticBatch` into a primitive-only `DiagnosticBatchSnapshot` made of string-backed execution identity, execution kind, ordered `DiagnosticObservationSnapshot` values and the dropped observation count. Snapshots do not retain Core `Observation`, execution identifier, request, response, container, throwable or execution-scope objects.

Current bounded behavior:

- collection starts only after Core reports an execution start
- active collection state is isolated by Core execution identifier value
- observations for unknown or already completed executions are ignored
- retained observations are bounded per execution
- dropped observation counts are deterministic and non-negative
- completed executions are forgotten before the finalized batch is handed to the configured sink
- sink failures propagate to the existing Core instrumentation boundary

Current storage behavior:

- `DiagnosticBatchStore` defines minimal save, exact execution-identifier lookup and newest-first bounded reads
- `InMemoryDiagnosticBatchStore` keeps snapshots in insertion order and returns newest batches first
- duplicate execution identifiers are rejected and never replace the original snapshot
- `StoringDiagnosticBatchSink` projects accepted batches and saves the detached snapshot through a configured store
- storage remains optional and unwired; installing Insight does not create storage automatically

The in-memory store is unbounded and suitable only for tests or short-lived local development. It is not a persistent runtime store.

## Requirements

PHP `^8.4`

## Dependencies

- `evolvephp/core`

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Current Limitations

This package does not provide filesystem or database persistence, retention, pruning, redaction, rich diagnostic capture, filtering, sampling, dashboards, watchers, OpenTelemetry, Evolve Observe, trace propagation, runtime composition, automatic registration, persistent storage adapters or production-ready diagnostics.

## Licence

BSD-3-Clause. See `LICENSE.md`.
