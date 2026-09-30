# EvolvePHP Storage Contracts

Vendor-neutral object storage contracts for EvolvePHP 2.

Package: `evolvephp/storage-contracts`.

EvolvePHP 2 is pre-release. This package requires PHP `^8.4`, depends only on `evolvephp/contracts`, and exposes experimental public API that may change before stable release. It is not yet independently published; the canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

`ObjectStorage` is a narrow vendor-neutral boundary for writing, opening, and deleting opaque objects. A `StorageKey` preserves its exact non-empty value up to 1024 bytes; it does not interpret path components or assign business meaning. Object naming, directory-like layout, tenancy prefixes, business identifiers, and logical metadata policy belong to the application or module.

Writes accept ordered `iterable<string>` chunks. Their concatenated bytes are stored in order, with an empty iterable representing an empty object. Implementations must support incremental chunk consumption, and the contract must not require whole-object materialization or buffering. A yielded non-string value is caller misuse and must be rejected with `InvalidArgumentException`; it is not translated into `StorageException`. The application retains ownership of any resource used by its chunk producer, and storage does not close that producer resource.

`open()` makes one immediate lookup and returns `null` when an object is absent. Provider/backend failures cross the `StorageException` boundary. A returned `ReadableObject` belongs to the caller and must be explicitly closed, normally in a `finally` block. Reads are bounded and sequential: the requested byte count must be positive, non-empty chunks are returned while data remains, and `null` marks end-of-object. `close()` is explicitly idempotent. No destructor-driven cleanup is required.

Deleting an absent object is successful completion. If a `put()` or `delete()` fails, whether it partially took effect is provider/backend-specific; the contract promises neither rollback nor atomic failure. Callers must not automatically retry a failed operation based on this contract. The contracts also do not promise atomic replacement, visibility timing, cross-client consistency, versioning, transactional durability, or exactly-once effects. They do not provide listing, directories, whole-object convenience methods, or vendor SDK integrations.

This package contains contracts only. It includes no local filesystem adapter, cloud adapter, storage SDK dependency, or telemetry coupling. This package is provided under the BSD-3-Clause licence. See `LICENSE.md`.
