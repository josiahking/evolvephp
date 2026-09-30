<?php

declare(strict_types=1);

namespace Evolve\Queue\Contracts;

use Evolve\Queue\Contracts\Exception\QueueException;

/**
 * Transient ownership and settlement capability for one received message.
 *
 * Application handling and execution cleanup/reset happen before adapter settlement. A failed
 * settlement may have an uncertain transport outcome, so repeating it is not guaranteed safe.
 * The meaning of rejection is backend-specific and must be documented by concrete adapters.
 *
 * @experimental EvolvePHP 2 is pre-beta; this queue contract may change before stable release.
 */
interface Delivery
{
    public function message(): MessageEnvelope;

    /**
     * @throws QueueException When successful settlement cannot be determined or completed.
     */
    public function acknowledge(): void;

    /**
     * @throws QueueException When negative settlement cannot be determined or completed.
     */
    public function reject(): void;
}
