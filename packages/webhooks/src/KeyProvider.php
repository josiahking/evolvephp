<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

/** Host resolves an explicit current key ID. Implementations reject missing, unknown, invalid, or expired keys at the supplied Unix time. */
interface KeyProvider
{
    public function resolve(string $keyId, int $at): ?string;
}
