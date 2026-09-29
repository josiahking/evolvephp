# EvolvePHP Cache Memory

`evolvephp/cache-memory`: In-memory PSR-16 cache implementation for EvolvePHP 2.

EvolvePHP 2 is pre-release. This package is not yet independently published; the canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

## Requirements

- PHP `^8.4`
- `psr/simple-cache`
- `psr/clock`

PSR-16 is the cache contract. Applications may depend on `Psr\SimpleCache\CacheInterface` and replace this package with another compatible PSR-16 implementation.

This package is a reference zero-backend implementation. Cache state is object-local application-lifetime memory: it is not static or global state, and it is intentionally not execution reset state. Clearing one `InMemoryCache` instance does not clear another instance.

No Redis requirement exists. This package makes no PSR-6 claim and does not provide cache tags, cache namespaces, stampede protection, atomic counters, compare-and-swap, distributed locks, session storage, service-container registration, environment/config discovery, telemetry, Insight integration or Observe integration.

Package dependencies: `psr/simple-cache`, `psr/clock`.

## License

BSD-3-Clause. See `LICENSE.md`.
