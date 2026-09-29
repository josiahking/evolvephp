<?php

declare(strict_types=1);

namespace Evolve\Lock\Contracts;

use Evolve\Lock\Contracts\Exception\LockException;

/**
 * Makes one non-blocking attempt to acquire a lease.
 *
 * @experimental EvolvePHP 2 is pre-beta; this lock contract may change before stable release.
 */
interface LockProvider
{
    /**
     * Returns null for ordinary contention. Backend failures use LockException.
     *
     * @throws LockException When the provider cannot determine or complete acquisition.
     */
    public function tryAcquire(LockKey $key, LeaseDuration $duration): ?Lease;
}
