<?php

declare(strict_types=1);

namespace Evolve\Insight\Infrastructure;

use Evolve\Queue\Contracts\Delivery;
use Evolve\Queue\Contracts\MessageEnvelope;

final readonly class DiagnosticDelivery implements Delivery
{
    private ?string $originIdentifier;

    public function __construct(private Delivery $delivery, private DiagnosticRecorder $recorder)
    {
        $this->originIdentifier = $recorder->correlation()->identifier();
    }

    public function message(): MessageEnvelope
    {
        return $this->delivery->message();
    }

    public function acknowledge(): void
    {
        if (!$this->canRecord()) {
            $this->delivery->acknowledge();

            return;
        }

        $this->recorder->run('queue', 'acknowledge', function (): null {
            $this->delivery->acknowledge();

            return null;
        });
    }

    public function reject(): void
    {
        if (!$this->canRecord()) {
            $this->delivery->reject();

            return;
        }

        $this->recorder->run('queue', 'reject', function (): null {
            $this->delivery->reject();

            return null;
        });
    }

    private function canRecord(): bool
    {
        return $this->originIdentifier !== null && $this->originIdentifier === $this->recorder->correlation()->identifier();
    }
}
