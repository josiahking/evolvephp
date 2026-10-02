# EvolvePHP Scheduler

`evolvephp/scheduler`: One-tick scheduling runtime for EvolvePHP 2. It is experimental and pre-beta; its public API may change before Stable.
EvolvePHP 2 is pre-release.

It requires PHP `^8.4`, EvolvePHP Core, lock contracts, queue contracts, PSR Clock, and `dragonmantank/cron-expression`.
This package is not yet independently published.
The canonical source is the [EvolvePHP monorepo](https://github.com/josiahking/evolvephp).
Runtime dependencies are `evolvephp/core`, `evolvephp/lock-contracts`, `evolvephp/queue-contracts`, `psr/clock` and `dragonmantank/cron-expression`.
The package uses the BSD-3-Clause licence in `LICENSE.md`.

Compose a frozen `ScheduleRegistry` from explicit `ScheduleContributor` objects, then give it to `SchedulerRunner` with a PSR clock and a Core `ExecutionOrchestrator`. Each `runTick()` reads the clock once, evaluates definitions in identifier order, runs due tasks sequentially, and returns a structured `SchedulerRunReport`. The caller may pass the previous checked instant. The scheduler keeps no persistent checkpoint. A process loop, polling, supervisor integration, and process recycling belong to runtime composition.

Every definition has a valid explicit timezone, defaulting to UTC, and may supply a locale. These values reach Core execution context without changing process-global timezone or locale state. Cron evaluation uses that timezone, including calendar and daylight-saving rules.

`CatchUpPolicy::Skip` considers only the current minute. `CatchUpPolicy::RunOnce` executes once when one or more occurrences fall in the supplied `(previousCheckExclusive, checkedAt]` window and reports the latest occurrence. Without a previous checkpoint, it behaves like Skip; startup does not replay historical work.

Allow-overlap needs no lock provider. Prevent-overlap requires one and makes one non-blocking acquisition inside Core execution. Contention reports overlap-skipped without calling the action. An acquired lease is reset by Core scope cleanup. Leases have caller-chosen durations. There is no waiting, retry, renewal, fencing, leader election, or exactly-once guarantee.

Callback actions receive Core `ExecutionContext` and `ExecutionScope`. Command actions execute the selected Core command directly inside the single scheduled execution and return its `CommandResult`. Queue-job actions publish one opaque message; they do not consume or settle deliveries. The package has no ORM or database dependency, although application callbacks may call persistence services through the scope.

The report exposes checked time, prior checkpoint, ordered attempted runs, exact Core execution outcomes, and a process reuse decision. Clean primary failures do not stop later due schedules. Cleanup or execution-start failures quarantine that runner.
