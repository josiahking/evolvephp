<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

use Throwable;

/** Verification requires host key resolution and an atomic replay store. */
final readonly class Verifier
{
    public function __construct(private ?ReplayStore $store, private KeyProvider $keys) {}

    /** @param array<string, string> $headers */
    public function verify(string $body, array $headers, int $now, int $maxSkew): bool
    {
        $keyId = $headers['X-Evolve-Webhook-Key'] ?? null;
        $deliveryId = $headers['X-Evolve-Webhook-Delivery'] ?? null;
        if ($this->store === null || !is_string($keyId) || $keyId === ''
            || !is_string($deliveryId) || ($headers['Idempotency-Key'] ?? null) !== $deliveryId) {
            return false;
        }
        try {
            $secret = $this->keys->resolve($keyId, $now);
            if ($secret === null || $secret === '' || !Signature::verify($body, $headers, $secret, $now, $maxSkew)) {
                return false;
            }
            return $this->store->claim(
                hash('sha256', pack('N', strlen($keyId)) . $keyId . pack('N', strlen($deliveryId)) . $deliveryId),
                (int) $headers['X-Evolve-Webhook-Timestamp'] + $maxSkew,
            );
        } catch (Throwable) {
            return false;
        }
    }
}
