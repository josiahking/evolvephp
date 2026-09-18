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
     * @var list<DiagnosticEntrySnapshot>
     */
    private array $diagnosticEntries;

    /**
     * @param list<DiagnosticObservationSnapshot> $observations
     * @param array<array-key, mixed> $diagnosticEntries
     */
    public function __construct(
        private string $executionIdentifier,
        private string $executionKind,
        array $observations,
        private int $droppedObservationCount,
        array $diagnosticEntries = array(),
        private int $droppedDiagnosticEntryCount = 0,
    ) {
        if ($this->executionIdentifier === '') {
            throw new \InvalidArgumentException('Execution identifier must not be empty.');
        }

        if ($this->droppedObservationCount < 0) {
            throw new \InvalidArgumentException('Dropped observation count must not be negative.');
        }

        if ($this->droppedDiagnosticEntryCount < 0) {
            throw new \InvalidArgumentException('Dropped diagnostic entry count must not be negative.');
        }

        $validatedDiagnosticEntries = array();

        foreach ($diagnosticEntries as $entry) {
            if (!$entry instanceof DiagnosticEntrySnapshot) {
                throw new \InvalidArgumentException('Diagnostic entries must be diagnostic entry snapshots.');
            }

            $validatedDiagnosticEntries[] = $entry;
        }

        $this->observations = $observations;
        $this->diagnosticEntries = $validatedDiagnosticEntries;
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

    /**
     * @return list<DiagnosticEntrySnapshot>
     */
    public function diagnosticEntries(): array
    {
        return $this->diagnosticEntries;
    }

    public function droppedDiagnosticEntryCount(): int
    {
        return $this->droppedDiagnosticEntryCount;
    }
}
