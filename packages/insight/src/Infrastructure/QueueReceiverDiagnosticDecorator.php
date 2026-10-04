<?php

declare(strict_types=1);

namespace Evolve\Insight\Infrastructure;

use Evolve\Queue\Contracts\Delivery;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueueReceiver;

final readonly class QueueReceiverDiagnosticDecorator implements QueueReceiver
{
    public function __construct(private QueueReceiver $receiver, private DiagnosticRecorder $recorder) {}

    public function receive(QueueName $queue): ?Delivery
    {
        $delivery = $this->recorder->run(
            'queue',
            'receive',
            fn(): ?Delivery => $this->receiver->receive($queue),
            static fn(?Delivery $result): array => ['delivery_present' => $result !== null],
        );

        return $delivery === null ? null : new DiagnosticDelivery($delivery, $this->recorder);
    }
}
