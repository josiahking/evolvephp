<?php

declare(strict_types=1);

namespace Evolve\Insight;

use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationSink;
use Evolve\Core\Instrumentation\ObservationType;

final class DiagnosticBatchCollector implements ObservationSink
{
    /**
     * @var array<string, array{identifier: ExecutionIdentifier, kind: ExecutionKind, observations: list<Observation>, dropped: int}>
     */
    private array $activeBatches = array();

    /**
     * @var \WeakMap<ExecutionIdentifier, true>
     */
    private \WeakMap $finalizedIdentifiers;

    public function __construct(
        private DiagnosticBatchSink $sink,
        private int $maximumRetainedObservationCount,
    ) {
        if ($this->maximumRetainedObservationCount <= 0) {
            throw new \InvalidArgumentException('Maximum retained observation count must be positive.');
        }

        $this->finalizedIdentifiers = new \WeakMap();
    }

    public function observe(Observation $observation): void
    {
        if (isset($this->finalizedIdentifiers[$observation->identifier()])) {
            return;
        }

        $identifierValue = $observation->identifier()->value();

        if ($observation->type() === ObservationType::ExecutionStarted && !isset($this->activeBatches[$identifierValue])) {
            $this->activeBatches[$identifierValue] = array(
                'identifier' => $observation->identifier(),
                'kind' => $observation->kind(),
                'observations' => array(),
                'dropped' => 0,
            );
        }

        if (!isset($this->activeBatches[$identifierValue])) {
            return;
        }

        $this->record($identifierValue, $observation);

        if ($observation->type() !== ObservationType::ExecutionCompleted) {
            return;
        }

        $state = $this->activeBatches[$identifierValue];
        unset($this->activeBatches[$identifierValue]);
        $this->finalizedIdentifiers[$observation->identifier()] = true;

        $this->sink->accept(new DiagnosticBatch(
            $state['identifier'],
            $state['kind'],
            $state['observations'],
            $state['dropped'],
        ));
    }

    private function record(string $identifierValue, Observation $observation): void
    {
        if (count($this->activeBatches[$identifierValue]['observations']) < $this->maximumRetainedObservationCount) {
            $this->activeBatches[$identifierValue]['observations'][] = $observation;

            return;
        }

        $this->activeBatches[$identifierValue]['dropped']++;
    }
}
