<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

use InvalidArgumentException;

final class Signature
{
    /** @return array<string, string> */
    public static function sign(string $body, string $deliveryId, string $keyId, string $secret, int $timestamp, ?string $eventId = null): array
    {
        self::assertToken($deliveryId);
        self::assertToken($keyId);
        if ($eventId !== null) {
            self::assertToken($eventId);
        }
        if ($secret === '' || $timestamp < 0) {
            throw new InvalidArgumentException('Invalid signing input.');
        }
        $headers = [
            'X-Evolve-Webhook-Timestamp' => (string) $timestamp,
            'X-Evolve-Webhook-Delivery' => $deliveryId,
            'X-Evolve-Webhook-Key' => $keyId,
            'Idempotency-Key' => $deliveryId,
            'X-Evolve-Webhook-Signature' => 'v1=' . hash_hmac('sha256', self::message($timestamp, $deliveryId, $keyId, $body, $eventId), $secret),
        ];
        if ($eventId !== null) {
            $headers['X-Evolve-Webhook-Event'] = $eventId;
        }
        return $headers;
    }

    /** @param array<string, mixed> $headers */
    public static function verify(string $body, array $headers, string $secret, int $now, int $maxSkew): bool
    {
        $timestamp = $headers['X-Evolve-Webhook-Timestamp'] ?? null;
        $deliveryId = $headers['X-Evolve-Webhook-Delivery'] ?? null;
        $keyId = $headers['X-Evolve-Webhook-Key'] ?? null;
        $idempotencyKey = $headers['Idempotency-Key'] ?? null;
        $eventId = $headers['X-Evolve-Webhook-Event'] ?? null;
        $signature = $headers['X-Evolve-Webhook-Signature'] ?? null;
        if ($secret === '' || $maxSkew < 1 || $maxSkew > 3600
            || !is_string($timestamp) || preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D', $timestamp) !== 1
            || !is_string($deliveryId) || !is_string($keyId) || !is_string($signature)
            || !is_string($idempotencyKey) || !hash_equals($deliveryId, $idempotencyKey)
            || ($eventId !== null && !is_string($eventId))
            || abs($now - (int) $timestamp) > $maxSkew) {
            return false;
        }
        try {
            self::assertToken($deliveryId);
            self::assertToken($keyId);
            if ($eventId !== null) {
                self::assertToken($eventId);
            }
        } catch (InvalidArgumentException) {
            return false;
        }
        $expected = 'v1=' . hash_hmac('sha256', self::message((int) $timestamp, $deliveryId, $keyId, $body, $eventId), $secret);
        return hash_equals($expected, $signature);
    }

    private static function message(int $timestamp, string $deliveryId, string $keyId, string $body, ?string $eventId): string
    {
        return $timestamp . "\n" . $deliveryId . "\n" . $keyId . "\n" . ($eventId ?? '') . "\n" . $body;
    }

    private static function assertToken(string $token): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $token) !== 1) {
            throw new InvalidArgumentException('Invalid webhook identifier.');
        }
    }
}
