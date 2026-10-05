<?php

declare(strict_types=1);

namespace Evolve\Insight\Infrastructure;

use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueuePublisher;

final readonly class QueuePublisherDiagnosticDecorator implements QueuePublisher
{
    public function __construct(private QueuePublisher $publisher, private DiagnosticRecorder $recorder) {}

    public function publish(QueueName $queue, MessageEnvelope $message): void
    {
        $this->recorder->run('queue', 'publish', function () use ($queue, $message): null {
            $this->publisher->publish($queue, $message);

            return null;
        });
    }
}
