<?php

declare(strict_types=1);

namespace Evolve\Insight\Storage;

use Evolve\Core\Execution\ProcessReuseDecision;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\DiagnosticBatch;

final class DiagnosticBatchProjector
{
    public function project(DiagnosticBatch $batch): DiagnosticBatchSnapshot
    {
        return new DiagnosticBatchSnapshot(
            $batch->identifier()->value(),
            $batch->kind()->value,
            array_map(
                fn (Observation $observation): DiagnosticObservationSnapshot => $this->projectObservation($observation),
                $batch->observations(),
            ),
            $batch->droppedObservationCount(),
            array_map(
                fn (DiagnosticEntry $entry): DiagnosticEntrySnapshot => $this->projectEntry($entry),
                $batch->diagnosticEntries(),
            ),
            $batch->droppedDiagnosticEntryCount(),
        );
    }

    private function projectObservation(Observation $observation): DiagnosticObservationSnapshot
    {
        return new DiagnosticObservationSnapshot(
            $this->observationType($observation->type()),
            $this->outcome($observation->outcome()),
            $observation->errorType(),
            $this->reuseDecision($observation->reuseDecision()),
        );
    }

    private function observationType(ObservationType $type): string
    {
        return match ($type) {
            ObservationType::ExecutionStarted => 'execution-started',
            ObservationType::HandlerCompleted => 'handler-completed',
            ObservationType::ScopeCloseStarted => 'scope-close-started',
            ObservationType::ScopeCloseCompleted => 'scope-close-completed',
            ObservationType::QuarantineRequired => 'quarantine-required',
            ObservationType::ExecutionCompleted => 'execution-completed',
        };
    }

    private function outcome(?ObservationOutcome $outcome): ?string
    {
        return match ($outcome) {
            ObservationOutcome::Succeeded => 'succeeded',
            ObservationOutcome::Failed => 'failed',
            null => null,
        };
    }

    private function reuseDecision(?ProcessReuseDecision $decision): ?string
    {
        return match ($decision) {
            ProcessReuseDecision::Reusable => 'reusable',
            ProcessReuseDecision::QuarantineRequired => 'quarantine-required',
            null => null,
        };
    }

    private function projectEntry(DiagnosticEntry $entry): DiagnosticEntrySnapshot
    {
        return new DiagnosticEntrySnapshot(
            $entry->category(),
            $entry->name(),
            array_map(
                fn (DiagnosticAttribute $attribute): DiagnosticEntryAttributeSnapshot => new DiagnosticEntryAttributeSnapshot(
                    $attribute->name(),
                    $attribute->value(),
                ),
                $entry->attributes(),
            ),
        );
    }
}
