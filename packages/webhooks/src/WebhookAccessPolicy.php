<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

/** Host authorization and supported event-type boundary. */
interface WebhookAccessPolicy
{
    public function canSubscribe(string $principal, string $tenantId): bool;
    public function canPublish(string $principal, string $tenantId, string $eventType): bool;
}
