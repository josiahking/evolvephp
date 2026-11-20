---
title: Webhooks
description: Explicit outbound webhook publication, signed attempts, and host security boundaries.
---

# Webhooks

The experimental evolvephp/webhooks package is not yet independently published. It is available as local source for PHP ^8.4. The host explicitly includes WebhooksModule in its Module SDK definitions; omitting it leaves the application inert. Registration and boot perform no SQL, migration, or network work.

## Application flow

Register a DatabaseConnection, a KeyProvider, a TrustedTransport, and a WebhookAccessPolicy in the host container. Run WebhooksMigrationContributor through the application migration runner before use. Authorize each tenant operation in application code, then call Webhooks::subscribe() with a principal and WebhookSubscription, Webhooks::publish() with a principal and application-created WebhookEvent, and Webhooks::deliver() with tenant, delivery ID, unique lease token, current Unix timestamp, and a maximum attempt count. No framework event interception, inbound endpoint, scheduler, or daemon is installed.

Publication stores the event and its tenant-matched delivery rows in one database transaction. Duplicate event IDs are rejected by the database. A claim is an atomic tenant-scoped SQL update. A successful 2xx response acknowledges a delivery; 3xx and non-429 4xx responses are terminal; 429 and 5xx responses are retryable. Transport exceptions are uncertain and may be retried. Expired claims can be reattempted after a crash, so a receiver may see the same delivery ID more than once. The stable ID is also sent in an Idempotency-Key header for receiver deduplication. Exactly-once delivery is not provided.

## Security boundary

DestinationPolicy rejects HTTP, URL credentials, localhost, literal IP hosts, fragments, non-443 ports, and malformed hostnames. The host's TrustedTransport must enforce public DNS resolution and IP pinning at connection time, reject redirects, cap response bytes at 64 KiB, enforce a connect timeout of at most 3 seconds and a read timeout of at most 10 seconds. The package cannot verify those guarantees from a generic PSR-18 client. Do not enable delivery without a reviewed host transport.

Signature signs the exact JSON body bytes with HMAC-SHA256 and binds the timestamp, delivery ID, and key ID. SecretKeyProvider adapts a host SecretResolver for one current key ID with an explicit expiry timestamp. Historical key versions and automatic rotation are unsupported; unknown keys fail closed. Verifier resolves the signed key ID and requires an atomic host ReplayStore before accepting a request. Missing or failing replay storage causes rejection. Signature verification alone does not prevent replay, and the package creates no inbound HTTP controller.

The host must keep signing keys secret, authorize subscription changes, and operate the delivery invocation schedule. The in-memory queue adapter does not provide durable delivery.