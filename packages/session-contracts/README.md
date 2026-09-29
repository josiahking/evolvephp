# EvolvePHP Session Contracts

Runtime-neutral session contracts for EvolvePHP 2.

Package: `evolvephp/session-contracts`.

EvolvePHP 2 is pre-release. This package requires PHP `^8.4`, depends on `evolvephp/contracts`, and exposes public experimental API that may change before stable release. It is not yet independently published; the canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

`Session` represents mutable data owned by one active execution. `has()` distinguishes a stored `null` value from a missing key, and `get()` returns the supplied default only when the key is absent. The contract intentionally has no bulk `all()` or export method, no `ArrayAccess`, no magic property access, no serialization contract, no flash semantics, no authentication or principal semantics, no CSRF semantics and no cookie behavior. Values are adapter-owned PHP values, and the contract does not promise storage-backend portability for arbitrary PHP values. Session contents must not be exposed through diagnostics or exception messages.

`SessionAdapter` extends `Evolve\Contracts\Execution\ResetParticipant` so active session state participates in execution cleanup. `open(null)` begins a fresh session and must not implicitly recover an identifier or data value from an earlier execution's ambient state. `open($identifier)` explicitly asks the adapter to open the session identified by that opaque identifier. `identifier()` is an explicit sensitive-data access point and is valid only while a session is active. `regenerate()` replaces the active identifier while preserving active data. `invalidate()` destroys and ends the active session. `close()` persists accepted mutations according to the concrete adapter's documented persistence semantics, releases locks/resources, clears current-session identifier/data references and is safe when already inactive.

`reset()` is the execution-cleanup safety boundary. It must close any active session, release locks/resources, clear active identifier/data references and propagate cleanup failure rather than swallowing it so existing execution reset and quarantine behavior can fail closed.

`SessionIdentifier` preserves an accepted non-empty identifier string exactly. Empty identifiers are rejected with `InvalidArgumentException`; accepted identifiers are not trimmed or normalized. The constructor parameter is marked `SensitiveParameter`, debug output redacts the raw value and the class does not implement `__toString()`.

This package provides no native PHP session implementation, storage implementation, cookie emission or synchronization, CSRF, flash messages, authentication policy, garbage collection, encryption, serialization format, middleware, configuration discovery, service registration, Insight integration or Observe integration. Concrete adapters remain outward implementations.

This contract does not promise distributed locking, fencing tokens, fairness, compare-and-swap, linearizability or atomic multi-backend behavior. Those guarantees belong to concrete adapters only when they can prove them.

This package is provided under the BSD-3-Clause licence. See `LICENSE.md`.
