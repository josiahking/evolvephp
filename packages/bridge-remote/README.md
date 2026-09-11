# EvolvePHP Bridge Remote

`evolvephp/bridge-remote` provides the experimental remote HTTP JSON Bridge protocol, PSR-18 host client and PSR-15 server endpoint for EvolvePHP 2.

Remote HTTP JSON Bridge protocol, PSR-18 host client and PSR-15 server endpoint for EvolvePHP 2.

The package defines a versioned transport-facing boundary for remote Bridge requests. The host-side `RemoteBridgeClient` encodes invocations with the shared protocol codec, builds one outer PSR request to a trusted configured endpoint, applies validated authentication headers and decodes a bounded remote protocol result. The server endpoint decodes a bounded JSON protocol message, requires an injected authenticator, constructs a delegated PSR request through PSR-17 factories, invokes the existing embedded PSR Bridge adapter, and returns a protocol response without emitting it.

The public API is experimental and currently limited to `Evolve\Bridge\Remote\RemoteBridgeProtocol`, `Evolve\Bridge\Remote\RemoteBridgeInvocation`, `Evolve\Bridge\Remote\RemoteBridgeResult`, `Evolve\Bridge\Remote\RemoteBridgeCodec`, `Evolve\Bridge\Remote\RemoteBridgeAuthenticator`, `Evolve\Bridge\Remote\RemoteBridgeServerHandler`, `Evolve\Bridge\Remote\RemoteBridgeClientAuthenticator`, `Evolve\Bridge\Remote\RemoteBridgeClientResult` and `Evolve\Bridge\Remote\RemoteBridgeClient`.

BridgeRemote keeps protocol and transport failures separate from delegated application responses. It preserves safe Bridge error kind, code, message and retryability fields, and it does not serialize exceptions, stack traces, credentials or arbitrary debug context.

Supported remote and sidecar topology:

```text
host PHP process
    -> RemoteBridgeClient
    -> PSR-18 HTTP transport
    -> optional reverse proxy
    -> remote/sidecar EvolvePHP endpoint
```

Sidecar deployment remains remote mode. The EvolvePHP endpoint keeps an independent PHP, Composer and process lifecycle even when it runs on localhost, in a private container group or behind a local reverse proxy.

The host application owns the concrete PSR-18 transport, bounded timeout configuration, controlled or disabled redirect behavior, fallback decisions and any retry policy. `RemoteBridgeClient` does not add retries, backoff, circuit breaking, automatic idempotency handling, redirect following, service discovery, health polling, process supervision, worker recycling or proxy-server behavior.

The configured client endpoint is trusted deployment configuration. `RemoteBridgeInvocation::target()` is the delegated application request target and is never used as the network destination.

TLS is required across untrusted or networked boundaries. Explicitly trusted localhost or private sidecar transport may use local HTTP according to deployment policy.

This package requires PHP `^8.4`.

EvolvePHP 2 is pre-release, and this package is not yet independently published. The canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

Dependencies: `evolvephp/bridge-contracts`, `evolvephp/bridge-psr`, `psr/http-client`, `psr/http-message`, `psr/http-factory` and `psr/http-server-handler`.

License: BSD-3-Clause. See `LICENSE.md`.
