<?php

declare(strict_types=1);

namespace Evolve\Lock\Contracts;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Lock\Contracts\Exception\LockException;

/**
 * Ownership capability for an acquired lock lease.
 *
 * @experimental EvolvePHP 2 is pre-beta; this lock contract may change before stable release.
 */
interface Lease extends ResetParticipant
{
    /**
     * Requests a fresh duration using the backend's authoritative timing semantics.
     *
     * False means ownership is definitively expired or lost; uncertainty is reported by exception.
     *
     * @throws LockException When renewal cannot be determined or completed.
     */
    public function renew(LeaseDuration $duration): bool;

    /**
     * Safely and idempotently releases this lease. Implementations must not release a lease
     * subsequently owned by another actor. Definite expiry, loss, or prior release is a no-op.
     *
     * @throws LockException When safe cleanup cannot be completed or determined.
     */
    public function release(): void;

    /**
     * Performs the same safe cleanup effect as release for execution reset/quarantine.
     * Cleanup failures remain visible to the reset lifecycle.
     *
     * @throws LockException When safe cleanup cannot be completed or determined.
     */
    public function reset(): void;
}
