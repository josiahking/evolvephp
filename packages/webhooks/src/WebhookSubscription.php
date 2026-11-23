<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

use InvalidArgumentException;

final readonly class WebhookSubscription
{
    public function __construct(
        public string $id,
        public string $eventType,
        public string $tenantId,
        public string $destination,
        public string $keyId,
    ) {
        foreach ([$id, $eventType, $tenantId, $keyId] as $value) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
                throw new InvalidArgumentException('Invalid webhook subscription identity.');
            }
        }
        DestinationPolicy::assertSafeUrl($destination);
    }

    public function matches(WebhookEvent $event): bool
    {
        return $this->tenantId === $event->tenantId && $this->eventType === $event->type;
    }
}
