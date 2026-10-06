# EvolvePHP Job

`evolvephp/job` provides the experimental One-shot queue job execution runtime for EvolvePHP 2.

`JobRunner::runOnce()` makes at most one receive attempt. A null delivery is idle. A received message is handled through Core `ExecutionOrchestrator` as a queue message. Core owns execution identity, context, scope, cleanup, and the primary result or throwable. The runner settles only after Core cleanup completes: success acknowledges once and handler failure rejects once. Cleanup failure or uncertain settlement quarantines the runner; later calls fail before another receive.

An application may supply a `JobExecutionContext` to `JobRunner`. The caller-owned wrapper receives the exact immutable message and a one-shot callable surrounding only the single Core execution. It must invoke the callable once and return that exact Core `ExecutionOutcome`. The wrapper enters after receipt and exits after Core cleanup, before acknowledgement or rejection. Skipping, repeating, or substituting the execution, or a wrapper failure, quarantines the runner and prevents settlement. The seam adds no retry, worker-loop, serialization, or transport behavior.

`JobRunOutcome` exposes the optional Core `ExecutionOutcome`, optional safe queue failure, settlement state, and final process reuse decision. Receive failures remain reusable; settlement failures are not retried. A Core execution start failure propagates unchanged and quarantines the runner after receipt.

The application supplies `QueueReceiver`, `ExecutionOrchestrator`, a handler, and optional `ExecutionContextValues`. The package has no polling loop, daemon, broker adapter, retry, backoff, scheduler, hard timeout, or exactly-once guarantee. It does not change process-global locale or timezone defaults.

This package requires PHP `^8.4`. The public API is experimental. EvolvePHP 2 is pre-release, and this package is not yet independently published. The canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

Dependencies: `evolvephp/core` and `evolvephp/queue-contracts`.

License: BSD-3-Clause. See `LICENSE.md`.
