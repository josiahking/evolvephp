<?php

declare(strict_types=1);

namespace Evolve\Insight;

use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Insight\Capture\DiagnosticEntry;

final class DiagnosticBatch
{
    /**
     * @var list<Observation>
     */
    private array $observations;

    /**
     * @var list<DiagnosticEntry>
     */
    private array $diagnosticEntries;

    /**
     * @param list<Observation> $observations
     * @param list<DiagnosticEntry> $diagnosticEntries
     */
    public function __construct(
        private ExecutionIdentifier $identifier,
        private ExecutionKind $kind,
        array $observations,
        private int $droppedObservationCount,
        array $diagnosticEntries = array(),
        private int $droppedDiagnosticEntryCount = 0,
    ) {
        if ($this->droppedObservationCount < 0) {
            throw new \InvalidArgumentException(
                'Dropped observation count must not be negative.'
            );
        }

        if ($this->droppedDiagnosticEntryCount < 0) {
            throw new \InvalidArgumentException(
                'Dropped diagnostic entry count must not be negative.'
            );
        }

        $this->observations = $observations;
        $this->diagnosticEntries = $diagnosticEntries;
    }

    public function identifier(): ExecutionIdentifier
    {
        return $this->identifier;
    }

    public function kind(): ExecutionKind
    {
        return $this->kind;
    }

    /**
     * @return list<Observation>
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
     * @return list<DiagnosticEntry>
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
