<?php

declare(strict_types=1);

namespace Evolve\Insight\Watcher;

use Evolve\Core\Execution\ProcessReuseDecision;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Capture\DiagnosticEntry;

final class ExecutionLifecycleDiagnosticWatcher implements ObservationDiagnosticWatcher
{
    public function watch(Observation $observation): array
    {
        return match ($observation->type()) {
            ObservationType::HandlerCompleted => $this->failedCompletionEntry(
                $observation,
                'evolve.execution',
                'handler-failed',
            ),
            ObservationType::ScopeCloseCompleted => $this->failedCompletionEntry(
                $observation,
                'evolve.runtime',
                'scope-close-failed',
            ),
            ObservationType::QuarantineRequired => array($this->entry(
                $observation,
                'evolve.runtime',
                'quarantine-required',
            )),
            default => array(),
        };
    }

    /**
     * @return list<DiagnosticEntry>
     */
    private function failedCompletionEntry(
        Observation $observation,
        string $category,
        string $name,
    ): array {
        if ($observation->outcome() !== ObservationOutcome::Failed) {
            return array();
        }

        return array($this->entry($observation, $category, $name));
    }

    private function entry(Observation $observation, string $category, string $name): DiagnosticEntry
    {
        $attributes = array(
            new DiagnosticAttribute(
                'execution_kind',
                DiagnosticDataClassification::PublicOperationalMetadata,
                $observation->kind()->value,
            ),
        );

        if ($observation->errorType() !== null) {
            $attributes[] = new DiagnosticAttribute(
                'error_type',
                DiagnosticDataClassification::InternalOperationalMetadata,
                $observation->errorType(),
            );
        }

        if ($observation->reuseDecision() !== null) {
            $attributes[] = new DiagnosticAttribute(
                'reuse_decision',
                DiagnosticDataClassification::PublicOperationalMetadata,
                $this->reuseDecision($observation->reuseDecision()),
            );
        }

        return new DiagnosticEntry(
            $observation->identifier()->value(),
            $category,
            $name,
            $attributes,
        );
    }

    private function reuseDecision(ProcessReuseDecision $decision): string
    {
        return match ($decision) {
            ProcessReuseDecision::Reusable => 'reusable',
            ProcessReuseDecision::QuarantineRequired => 'quarantine-required',
        };
    }
}
