<?php

declare(strict_types=1);

namespace Evolve\Insight\Storage;

final readonly class DiagnosticObservationSnapshot
{
    public function __construct(
        private string $type,
        private ?string $outcome,
        private ?string $errorType,
        private ?string $reuseDecision,
    ) {}

    public function type(): string
    {
        return $this->type;
    }

    public function outcome(): ?string
    {
        return $this->outcome;
    }

    public function errorType(): ?string
    {
        return $this->errorType;
    }

    public function reuseDecision(): ?string
    {
        return $this->reuseDecision;
    }
}
