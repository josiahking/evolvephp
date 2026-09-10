# EvolvePHP Bridge PSR

`evolvephp/bridge-psr` provides the experimental Same-process PSR HTTP Bridge adapter foundation for EvolvePHP 2.

EvolvePHP 2 is pre-release, and this package is not yet independently published. The canonical source is the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

The package requires PHP `^8.4` and depends on `evolvephp/bridge-contracts`, `evolvephp/core`, `evolvephp/http` and `psr/http-message`.

The adapter composes the existing HTTP execution kernel, response-resolution boundary and readiness abstraction. The host owns request acceptance, top-level process lifecycle and final response emission. Evolve owns delegated HTTP execution, execution-scope creation, cleanup/reset and result production.

The public API is experimental and currently limited to `Evolve\Bridge\Psr\EmbeddedBridgeAdapter` and `Evolve\Bridge\Psr\EmbeddedBridgeResult`.

This package does not provide PSR-15 middleware integration, Laravel integration, Symfony integration, remote protocol handling, concrete PSR-7 implementations, response emission, route cutover, retry policy, process recycling or compatibility certification.

This package uses the BSD-3-Clause license. See `LICENSE.md`.
