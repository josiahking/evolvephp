<?php

declare(strict_types=1);

namespace Evolve\Queue\Contracts;

use Evolve\Queue\Contracts\Exception\QueueException;

/**
 * Publishes an opaque message to a named queue.
 *
 * @experimental EvolvePHP 2 is pre-beta; this queue contract may change before stable release.
 */
interface QueuePublisher
{
    /**
     * @throws QueueException When the provider/backend cannot determine or complete publication.
     */
    public function publish(
        QueueName $queue,
        MessageEnvelope $message,
    ): void;
}
