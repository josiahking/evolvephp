<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

final readonly class DeliveryWork
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $leaseToken,
        public int $attempts,
        public int $maxAttempts,
        public WebhookEvent $event,
        public WebhookSubscription $subscription,
    ) {}
}
