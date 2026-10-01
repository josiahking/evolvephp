# EvolvePHP Secret Contracts

Vendor-neutral secret resolution contracts for EvolvePHP 2.

Package: `evolvephp/secret-contracts`.

EvolvePHP 2 is pre-release. This package requires PHP `^8.4`, depends only on `evolvephp/contracts`, and exposes experimental public API that may change before stable release. It is not yet independently published; the canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

`SecretResolver::resolve()` asks an explicitly supplied provider adapter for its current or default value for an opaque `SecretName`. The name preserves exact non-empty bytes, up to 2048 bytes, except NUL. It has no path or environment-variable interpretation. A currently absent or unresolved secret returns `null`; provider and backend failures cross the `SecretException` boundary with a portable `SecretFailureCategory`.

`SecretValue` preserves exact bytes, including an empty string, NUL bytes, and non-UTF-8 data. The name and value objects redact their debug display and have no string-conversion method. Callers can deliberately obtain bytes through `value()` and remain responsible for keeping those bytes out of logs, errors, and diagnostics. PHP string memory erasure is not guaranteed.

The contract does not define version selection, writes, rotation, listing, cache freshness, retry, fallback, global or environment discovery, or dynamic-secret lease behavior. It includes no vendor SDK or provider adapter.

Licensed under BSD-3-Clause; see [`LICENSE.md`](LICENSE.md).
