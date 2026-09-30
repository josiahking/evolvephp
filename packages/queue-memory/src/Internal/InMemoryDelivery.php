<?php

declare(strict_types=1);

namespace Evolve\Queue\Memory\Internal;

use Evolve\Queue\Contracts\Delivery;
use Evolve\Queue\Contracts\MessageEnvelope;

/**
 * Local delivery capability whose message has already left the available queue.
 *
 * @internal
 */
final class InMemoryDelivery implements Delivery
{
    private bool $settled = false;

    public function __construct(private readonly MessageEnvelope $message) {}

    public function message(): MessageEnvelope
    {
        return $this->message;
    }

    public function acknowledge(): void
    {
        $this->settle();
    }

    public function reject(): void
    {
        $this->settle();
    }

    private function settle(): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
    }
}
