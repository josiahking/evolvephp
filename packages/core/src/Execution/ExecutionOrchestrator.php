<?php

declare(strict_types=1);

namespace Evolve\Core\Execution;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Instrumentation\InstrumentationFailure;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationDispatcher;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Core\Instrumentation\ObservationSink;
use Evolve\Core\Instrumentation\ObservationType;
use Throwable;

final class ExecutionOrchestrator
{
    private bool $quarantined = false;

    private ObservationDispatcher $observations;

    public function __construct(private ServiceRegistry $services, ?ObservationSink $observationSink = null)
    {
        $this->observations = new ObservationDispatcher($observationSink);
    }

    /**
     * @param callable(ExecutionContext, ExecutionScope): mixed $operation
     */
    public function execute(ExecutionKind $kind, callable $operation): ExecutionOutcome
    {
        if ($this->quarantined) {
            throw new ExecutionStartFailed('Execution orchestrator is quarantined and cannot accept more work.');
        }

        try {
            $identifier = ExecutionIdentifier::generate();
            $scope = $this->services->createExecutionScope();
        } catch (Throwable $exception) {
            throw new ExecutionStartFailed('Execution could not be started.', 0, $exception);
        }

        $context = new ExecutionContext($identifier, $kind);
        $primarySucceeded = false;
        $primaryResult = null;
        $primaryThrowable = null;
        $cleanupThrowable = null;
        $instrumentationFailures = [];

        $this->observe($instrumentationFailures, new Observation(
            ObservationType::ExecutionStarted,
            $identifier,
            $kind,
        ));

        try {
            $primaryResult = $operation($context, $scope);
            $primarySucceeded = true;
        } catch (Throwable $exception) {
            $primaryThrowable = $exception;
        }

        $this->observe($instrumentationFailures, new Observation(
            ObservationType::HandlerCompleted,
            $identifier,
            $kind,
            $primarySucceeded ? ObservationOutcome::Succeeded : ObservationOutcome::Failed,
            $primaryThrowable === null ? null : $primaryThrowable::class,
        ));
        $this->observe($instrumentationFailures, new Observation(
            ObservationType::ScopeCloseStarted,
            $identifier,
            $kind,
        ));

        try {
            $scope->close();
        } catch (Throwable $exception) {
            $cleanupThrowable = $exception;
            $this->quarantined = true;
        }

        $reuseDecision = $cleanupThrowable === null
            ? ProcessReuseDecision::Reusable
            : ProcessReuseDecision::QuarantineRequired;

        $this->observe($instrumentationFailures, new Observation(
            ObservationType::ScopeCloseCompleted,
            $identifier,
            $kind,
            $cleanupThrowable === null ? ObservationOutcome::Succeeded : ObservationOutcome::Failed,
            $cleanupThrowable === null ? null : $cleanupThrowable::class,
        ));

        if ($reuseDecision === ProcessReuseDecision::QuarantineRequired) {
            $this->observe($instrumentationFailures, new Observation(
                ObservationType::QuarantineRequired,
                $identifier,
                $kind,
                reuseDecision: $reuseDecision,
            ));
        }

        $this->observe($instrumentationFailures, new Observation(
            ObservationType::ExecutionCompleted,
            $identifier,
            $kind,
            $primarySucceeded ? ObservationOutcome::Succeeded : ObservationOutcome::Failed,
            reuseDecision: $reuseDecision,
        ));

        unset($scope);

        if ($primarySucceeded) {
            return ExecutionOutcome::succeeded($identifier, $kind, $primaryResult, $cleanupThrowable, $instrumentationFailures);
        }

        return ExecutionOutcome::failed($identifier, $kind, $primaryThrowable, $cleanupThrowable, $instrumentationFailures);
    }

    /**
     * @param list<InstrumentationFailure> $instrumentationFailures
     */
    private function observe(array &$instrumentationFailures, Observation $observation): void
    {
        $failure = $this->observations->observe($observation);

        if ($failure !== null) {
            $instrumentationFailures[] = $failure;
        }
    }
}
