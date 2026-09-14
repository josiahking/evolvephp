<?php

declare(strict_types=1);

namespace Evolve\Insight;

use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Instrumentation\Observation;

final class DiagnosticBatch
{
    /**
     * @var list<Observation>
     */
    private array $observations;

    /**
     * @param list<Observation> $observations
     */
    public function __construct(
        private ExecutionIdentifier $identifier,
        private ExecutionKind $kind,
        array $observations,
        private int $droppedObservationCount,
    ) {
        if ($this->droppedObservationCount < 0) {
            throw new \InvalidArgumentException(
                'Dropped observation count must not be negative.'
            );
        }

        $this->observations = $observations;
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
}
