<?php

declare(strict_types=1);

namespace Evolve\Insight\Storage;

final class DiagnosticBatchSnapshot
{
    /**
     * @var list<DiagnosticObservationSnapshot>
     */
    private array $observations;

    /**
     * @param list<DiagnosticObservationSnapshot> $observations
     */
    public function __construct(
        private string $executionIdentifier,
        private string $executionKind,
        array $observations,
        private int $droppedObservationCount,
    ) {
        if ($this->executionIdentifier === '') {
            throw new \InvalidArgumentException('Execution identifier must not be empty.');
        }

        if ($this->droppedObservationCount < 0) {
            throw new \InvalidArgumentException('Dropped observation count must not be negative.');
        }

        $this->observations = $observations;
    }

    public function executionIdentifier(): string
    {
        return $this->executionIdentifier;
    }

    public function executionKind(): string
    {
        return $this->executionKind;
    }

    /**
     * @return list<DiagnosticObservationSnapshot>
     */
    public function observations(): array
    {
        return $this->observations;
    }

    public function droppedObservationCount(): int
    {
        return $this->droppedObservationCount;
    }
}
