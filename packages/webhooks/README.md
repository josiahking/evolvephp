# EvolvePHP Webhooks

Composer package: `evolvephp/webhooks`; requires PHP `^8.4`.

Experimental first-party outbound webhook module for EvolvePHP 2. This package is local source only; the package is not yet independently published or registered on Packagist.

Explicit tenant-scoped outbound webhooks with signed delivery and host-controlled transport.

EvolvePHP 2 is pre-release. This package is not yet independently published. The canonical source is the EvolvePHP monorepo at https://github.com/josiahking/evolvephp. The source inventory includes this package, but no separate GitHub package release or Packagist registration is claimed.

The package depends on `evolvephp/contracts`, `evolvephp/database-contracts`, `evolvephp/i18n`, `evolvephp/migration`, `evolvephp/module`, `evolvephp/secret-contracts`. It also directly requires `psr/container` for Module SDK service factory signatures. PHP `^8.4` is required. `LICENSE.md` carries the root BSD-3-Clause license byte for byte.

Applications explicitly include `WebhooksModule` in a Module SDK definition. Registration only contributes service factories. The host runs `WebhooksMigrationContributor` through the migration runner as a separate application operation. No endpoint, automatic event interception, background daemon, network call, or migration runs during registration or boot.

## Host responsibilities

The host supplies `DatabaseConnection`, `KeyProvider`, `TrustedTransport`, and `WebhookAccessPolicy`. `SecretKeyProvider` adapts the current value of an existing `SecretResolver` to one explicit key ID with a required expiry timestamp. Historical key versions and automatic rotation are unsupported; unknown IDs fail closed.

`TrustedTransport` is a security boundary. Its implementation must enforce HTTPS on port 443, public DNS resolution and IP pinning on every connection, no redirects, a 64 KiB response cap, a connect timeout of at most 3 seconds, and a read timeout of at most 10 seconds. A generic PSR-18 client does not prove those guarantees. Do not enable delivery until the host provides a reviewed transport meeting this contract. `DestinationPolicy` rejects URL credentials, HTTP, local names, and all literal IP targets before transport.

The migration requests the database transaction mode. Its DDL rollback behavior beyond the tested SQLite adapter is unverified; hosts must check their database before applying it.

The host invokes `Webhooks::subscribe` and `publish` with an explicit principal and invokes `deliver` with tenant and delivery identity. WebhookAccessPolicy authorizes subscription ownership and supported publication types. A published event and its matched deliveries are inserted in one database transaction. Event IDs are globally unique. Delivery claims are atomic SQL updates scoped by tenant and lease token. A lease timeout permits reattempt after a crash; a remote receiver may have accepted the earlier attempt. Consumers should deduplicate by the stable delivery ID, also sent as the Idempotency-Key header. Exactly-once delivery is not promised. No scheduler or queue worker is installed.

## Signatures and replay

The body is the validated JSON payload. `Signature` signs the exact transmitted body bytes and binds the timestamp, delivery ID, key ID, and event ID when present with HMAC-SHA256. `Idempotency-Key` must equal the signed delivery ID; changing or removing it rejects verification. `Verifier` requires both a host key provider and an atomic `ReplayStore`; absent or failing replay storage rejects the request. Signature checking alone does not prevent replay. The package creates no inbound HTTP endpoint.

Delivery responses are acknowledged for 2xx, terminal for 3xx and non-429 4xx, and retryable for 429 and 5xx. Transport failures are uncertain. Retry delay is bounded exponential backoff, with a host-chosen maximum attempt count of 1–20. The host must invoke eligible queued deliveries; the module does not schedule them.

Package-local English diagnostic messages are in resources/lang/en.php. Register them under the webhooks namespace with the existing i18n catalog and use WebhookDiagnosticLabel with an explicit LocalizationContext.
