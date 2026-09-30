# EvolvePHP Local Filesystem Storage

Local filesystem implementation of the EvolvePHP object storage contracts.

Package: `evolvephp/storage-local`.

This experimental EvolvePHP 2 package requires PHP `^8.4` and `evolvephp/storage-contracts:^2.0`. It is not yet independently published; its canonical source is the EvolvePHP monorepo.

Construct `LocalFilesystemStorage` with an explicit, absolute path to an existing directory. The adapter canonicalizes that directory once. It does not discover a root from the current working directory or environment. Its debug output redacts the configured path.

Object keys are treated as opaque bytes. The adapter stores objects below the reserved `.evolvephp-storage/v1/objects/` subtree using a SHA-256 digest of the exact key bytes, so key contents do not become path components. Each object file uses a versioned private on-disk format: the `EVPHPST1` magic/version, an unsigned 16-bit big-endian key length, the exact opaque key bytes, and the remaining bytes as payload. This experimental format belongs to this adapter; it does not add a storage-contract API. The adapter validates the header and key when opening, replacing, or deleting an existing object.

`put()` consumes chunks incrementally and writes a temporary file in the destination shard before publishing it. It does not require whole-object materialization. A yielded non-string chunk is caller misuse and raises `InvalidArgumentException`; producer exceptions pass through unchanged. Temporary-file cleanup is best effort after failure. A failed write or delete may have partially taken effect. The adapter does not promise rollback, atomic failure, durability after power loss, cross-process coordination, or safe behavior against hostile concurrent filesystem changes, so callers must not automatically retry based on the storage contract.

`open()` returns `null` for a missing object. Its returned reader provides bounded sequential reads and must be closed by the caller. Deleting a missing object succeeds. Filesystem/provider failures use the storage exception boundary with a bounded operation and unknown failure category.

The adapter owns only its reserved storage subtree. It does not provide Flysystem, S3 or other cloud SDKs, presigned URLs, multipart or resumable uploads, listing, copy or move, byte ranges, versioning, ACL policy, encryption policy, checksums, retention, a content metadata schema, automatic retries, locking or cross-process concurrency coordination, telemetry, a Core or application storage-directory convention, or remote consistency guarantees. This package is provided under the BSD-3-Clause licence. See `LICENSE.md`.
