# EvolvePHP Bridge Remote

`evolvephp/bridge-remote` provides the experimental remote HTTP JSON Bridge protocol and PSR-15 server endpoint for EvolvePHP 2.

Remote HTTP JSON Bridge protocol and PSR-15 server endpoint for EvolvePHP 2.

The package defines a versioned transport-facing boundary for remote Bridge requests. It decodes a bounded JSON protocol message, requires an injected authenticator, constructs a delegated PSR request through PSR-17 factories, invokes the existing embedded PSR Bridge adapter, and returns a protocol response without emitting it.

The public API is experimental and currently limited to `Evolve\Bridge\Remote\RemoteBridgeProtocol`, `Evolve\Bridge\Remote\RemoteBridgeInvocation`, `Evolve\Bridge\Remote\RemoteBridgeResult`, `Evolve\Bridge\Remote\RemoteBridgeCodec`, `Evolve\Bridge\Remote\RemoteBridgeAuthenticator` and `Evolve\Bridge\Remote\RemoteBridgeServerHandler`.

BridgeRemote keeps protocol and transport failures separate from delegated application responses. It preserves safe Bridge error kind, code, message and retryability fields, and it does not serialize exceptions, stack traces, credentials or arbitrary debug context.

This package requires PHP `^8.4`.

EvolvePHP 2 is pre-release, and this package is not yet independently published. The canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

Dependencies: `evolvephp/bridge-contracts`, `evolvephp/bridge-psr`, `psr/http-message`, `psr/http-factory` and `psr/http-server-handler`.

License: BSD-3-Clause. See `LICENSE.md`.
