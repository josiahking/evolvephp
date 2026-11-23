<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

/** Host implementation must atomically insert the key once through expiresAt. */
interface ReplayStore
{
    public function claim(string $key, int $expiresAt): bool;
}
