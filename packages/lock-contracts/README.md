# EvolvePHP Lock Contracts

Runtime-neutral lock and lease contracts for EvolvePHP 2.

Package: `evolvephp/lock-contracts`.

EvolvePHP 2 is pre-release. This package requires PHP `^8.4`, depends only on `evolvephp/contracts`, and exposes public experimental API that may change before stable release. It is not yet independently published; the canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

`LockProvider::tryAcquire()` makes one non-blocking acquisition attempt. It returns a `Lease` when acquired and `null` for ordinary contention. Provider and backend failures use the `LockException` catch boundary. The API has no wait policy.

`Lease` is the ownership capability. It can request renewal for a positive `LeaseDuration`; `false` means ownership is definitively expired or lost, while uncertainty is an exception. `release()` is caller-idempotent, and `reset()` provides the same safe cleanup effect for explicit execution reset registration. Implementations must not release a lock that has since been acquired by another actor, and cleanup failures must remain visible to the reset/quarantine lifecycle.

`LockKey` preserves a non-empty key exactly and exposes it only through its explicit `value()` accessor. Constructor input is sensitive and debug output is redacted. `LeaseDuration` preserves a positive integer number of milliseconds. Durations express requests; this contract does not promise a portable exact wall-clock expiry timestamp. Renewal follows the concrete backend's authoritative timing semantics.

This package is an experimental contract boundary. It provides no concrete adapter and does not promise fairness, fencing, linearizability, consensus, a particular distributed locking algorithm, reentrant behavior, multi-key coordination, or exactly-once execution. It also provides no ambient/current-lock registry or force-unlock operation. Concrete adapters must state and prove their own guarantees.

This package is provided under the BSD-3-Clause licence. See `LICENSE.md`.
