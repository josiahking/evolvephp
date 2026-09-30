<?php

declare(strict_types=1);

namespace Evolve\Queue\Contracts;

use Evolve\Queue\Contracts\Exception\QueueException;

/**
 * Attempts one non-blocking receive operation from a queue.
 *
 * A null result means that no delivery is currently available. Provider failures are exposed
 * through QueueException. Implementations must not turn this operation into polling, sleeping,
 * blocking, backoff, or retry behavior.
 *
 * @experimental EvolvePHP 2 is pre-beta; this queue contract may change before stable release.
 */
interface QueueReceiver
{
    /**
     * A null result means that no delivery is currently available, not that the attempt failed.
     *
     * @throws QueueException When the provider/backend cannot determine or complete this receive attempt.
     */
    public function receive(QueueName $queue): ?Delivery;
}
