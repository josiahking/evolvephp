<?php

declare(strict_types=1);

namespace Evolve\Queue\Memory;

use Evolve\Queue\Contracts\Delivery;
use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueuePublisher;
use Evolve\Queue\Contracts\QueueReceiver;
use Evolve\Queue\Memory\Internal\InMemoryDelivery;
use SplQueue;

/**
 * Object-local, non-durable FIFO queue adapter for tests and local development.
 *
 * @experimental EvolvePHP 2 is pre-beta; this adapter may change before stable release.
 */
final class InMemoryQueue implements QueuePublisher, QueueReceiver
{
    /** @var array<string, SplQueue<MessageEnvelope>> */
    private array $queues = [];

    public function publish(QueueName $queue, MessageEnvelope $message): void
    {
        $key = $this->queueKey($queue);
        $this->queues[$key] ??= new SplQueue();
        $this->queues[$key]->enqueue($message);
    }

    public function receive(QueueName $queue): ?Delivery
    {
        $key = $this->queueKey($queue);
        $messages = $this->queues[$key] ?? null;

        if ($messages === null) {
            return null;
        }

        $message = $messages->dequeue();

        if ($messages->isEmpty()) {
            unset($this->queues[$key]);
        }

        return new InMemoryDelivery($message);
    }

    private function queueKey(QueueName $queue): string
    {
        return 'q:' . $queue->value();
    }
}
