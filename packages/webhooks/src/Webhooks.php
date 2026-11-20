<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

use InvalidArgumentException;
use Throwable;

/** Explicit application service. Hosts invoke publication and delivery themselves. */
final readonly class Webhooks
{
    public function __construct(private SqlWebhookStore $store, private DeliveryAttempt $attempt, private WebhookAccessPolicy $access) {}

    public function subscribe(string $principal, WebhookSubscription $subscription): void
    {
        if (!$this->access->canSubscribe($principal, $subscription->tenantId)) {
            throw new InvalidArgumentException('Webhook subscription is not authorized.');
        }
        $this->store->addSubscription($subscription);
    }

    /** @return list<string> */
    public function publish(string $principal, WebhookEvent $event): array
    {
        if (!$this->access->canPublish($principal, $event->tenantId, $event->type)) {
            throw new InvalidArgumentException('Webhook publication is not authorized.');
        }
        return $this->store->publish($event);
    }

    /** Returns null when no eligible delivery was claimed. */
    public function deliver(string $tenantId, string $deliveryId, string $leaseToken, int $now, int $maxAttempts = 5): ?string
    {
        $work = $this->store->claim($tenantId, $deliveryId, $leaseToken, $now, $maxAttempts);
        if ($work === null) {
            return null;
        }
        try {
            $result = $this->attempt->sendResult($work->event, $work->subscription, $work->id, $now);
        } catch (Throwable) {
            $result = new DeliveryResult('retry', null);
        }
        $delay = min(3600, 60 * (2 ** min($work->attempts - 1, 6)));
        $this->store->complete($work, $result->outcome, $result->status, $now + $delay);
        return $result->outcome;
    }
}
