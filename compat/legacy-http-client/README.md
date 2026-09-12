# EvolvePHP Legacy HTTP Client

`evolvephp/legacy-http-client` is an isolated remote compatibility artifact for unsupported legacy PHP applications that need to invoke the EvolvePHP Remote Bridge protocol v1 over HTTP.

This artifact targets PHP `^7.4 || ^8.0` and has no runtime dependency on EvolvePHP Core, Bridge, PSR packages or the monorepo root. EvolvePHP 2 packages continue to require PHP `^8.4`; this client does not lower those requirements and does not enable embedded or same-process EvolvePHP on PHP 7.

The client sends exactly one protocol-v1 `POST` request to a configured HTTP or HTTPS endpoint using media type `application/vnd.evolve.bridge.remote.v1+json`. It performs bounded JSON encoding/decoding, validates safe request and response fields, preserves remote application results, safe Bridge error information, reusable state and quarantine state, and reports local configuration, authentication, protocol, timeout, transport and uncertain-outcome failures without exposing server internals.

Authentication is caller supplied through `LegacyRemoteClientAuthenticator`. The artifact does not implement JWT, HMAC, OAuth, API-key storage, credential discovery or secret rotation. Authentication headers are validated and cannot override protocol-controlled or unsafe transport headers.

`LegacyCurlTransport` is the optional cURL transport included for legacy hosts. It uses POST only, disables redirects, captures response bodies and headers, applies explicit connect/request timeouts and keeps TLS peer and host verification enabled. HTTP remains usable only for explicitly trusted local or private sidecar deployments; networked or untrusted deployments require TLS.

It does not provide automatic retries, retry backoff, circuit breakers, fallback routing, idempotency persistence, shared PHP sessions, cookie bridging, uploaded-file transport, streaming or binary protocol support, queues, service discovery, proxying, process supervision, cutover or data migration.
