# EvolvePHP Bridge Contracts

Generic transport-neutral Bridge contracts for EvolvePHP 2.

Package: `evolvephp/bridge-contracts`.

EvolvePHP 2 is pre-release. This package requires PHP `^8.4` and depends on `evolvephp/contracts`. Its current public API is experimental and may change before stable release.

The package defines the request, context, response and error boundary used by Bridge integrations. Host-framework types do not enter this package. It is not a PSR-7 or PSR-15 adapter, not the remote HTTP/JSON protocol, not a Laravel or Symfony adapter, and not an embedded execution implementation.

This package does not provide a remote client, remote server, automatic compatibility certification, route delegation, authentication-token validation, retry handling, trace propagation or migration execution.

Package publication has not started, and this package is not yet independently published. The canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

This package is provided under the BSD-3-Clause licence. See `LICENSE.md`.
