<?php

declare(strict_types=1);

namespace Evolve\Insight\Query;

use Evolve\Insight\Storage\DiagnosticBatchSnapshot;

final readonly class DiagnosticBatchSummary
{
    public function __construct(
        private string $executionIdentifier,
        private string $executionKind,
        private int $observationCount,
        private int $diagnosticEntryCount,
        private int $droppedObservationCount,
        private int $droppedDiagnosticEntryCount,
    ) {
        if ($this->executionIdentifier === '') {
            throw new \InvalidArgumentException('Diagnostic batch summary execution identifier must not be empty.');
        }

        if ($this->executionKind === '') {
            throw new \InvalidArgumentException('Diagnostic batch summary execution kind must not be empty.');
        }

        foreach (array(
            $this->observationCount,
            $this->diagnosticEntryCount,
            $this->droppedObservationCount,
            $this->droppedDiagnosticEntryCount,
        ) as $count) {
            if ($count < 0) {
                throw new \InvalidArgumentException('Diagnostic batch summary counts must not be negative.');
            }
        }
    }

    public static function fromSnapshot(DiagnosticBatchSnapshot $snapshot): self
    {
        return new self(
            $snapshot->executionIdentifier(),
            $snapshot->executionKind(),
            count($snapshot->observations()),
            count($snapshot->diagnosticEntries()),
            $snapshot->droppedObservationCount(),
            $snapshot->droppedDiagnosticEntryCount(),
        );
    }

    public function executionIdentifier(): string
    {
        return $this->executionIdentifier;
    }

    public function executionKind(): string
    {
        return $this->executionKind;
    }

    public function observationCount(): int
    {
        return $this->observationCount;
    }

    public function diagnosticEntryCount(): int
    {
        return $this->diagnosticEntryCount;
    }

    public function droppedObservationCount(): int
    {
        return $this->droppedObservationCount;
    }

    public function droppedDiagnosticEntryCount(): int
    {
        return $this->droppedDiagnosticEntryCount;
    }
}
