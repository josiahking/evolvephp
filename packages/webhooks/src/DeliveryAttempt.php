<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Explicit single attempt; durable scheduling and atomic claims belong to the store. */
final readonly class DeliveryAttempt
{
    public function __construct(private KeyProvider $keys, private TrustedTransport $transport) {}

    public function send(WebhookEvent $event, WebhookSubscription $subscription, string $deliveryId, int $timestamp): string
    {
        return $this->sendResult($event, $subscription, $deliveryId, $timestamp)->outcome;
    }

    public function sendResult(WebhookEvent $event, WebhookSubscription $subscription, string $deliveryId, int $timestamp): DeliveryResult
    {
        if (!$subscription->matches($event)) {
            throw new InvalidArgumentException('Webhook delivery is outside the subscription scope.');
        }
        $secret = $this->keys->resolve($subscription->keyId, $timestamp);
        if ($secret === null || $secret === '') {
            throw new RuntimeException('Webhook signing key is unavailable.');
        }
        $body = $event->payloadJson();
        $headers = Signature::sign($body, $deliveryId, $subscription->keyId, $secret, $timestamp, $event->id);
        $headers['Content-Type'] = 'application/json';
        try {
            $status = $this->transport->send(DestinationPolicy::assertSafeUrl($subscription->destination), $body, $headers);
        } catch (Throwable) {
            return new DeliveryResult('uncertain', null);
        }
        return new DeliveryResult(ResponsePolicy::classify($status), $status);
    }
}
