<?php

declare(strict_types=1);

namespace Evolve\Core\Execution;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionCleanupFailed;
use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Instrumentation\InstrumentationFailure;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationDispatcher;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Core\Instrumentation\ObservationSink;
use Evolve\Core\Instrumentation\ObservationType;
use InvalidArgumentException;
use Throwable;

final class ExecutionOrchestrator
{
    private bool $quarantined = false;

    private ObservationDispatcher $observations;

    /**
     * @var list<ExecutionContextAttacher>
     */
    private array $executionContextAttachers;

    /**
     * @param ObservationSink|array<array-key, ObservationSink>|null $observationSink
     * @param array<array-key, mixed> $executionContextAttachers
     */
    public function __construct(
        private ServiceRegistry $services,
        ObservationSink|array|null $observationSink = null,
        array $executionContextAttachers = [],
    ) {
        $this->observations = new ObservationDispatcher($observationSink);
        $this->executionContextAttachers = $this->normalizeExecutionContextAttachers($executionContextAttachers);
    }

    /**
     * @param callable(ExecutionContext, ExecutionScope): mixed $operation
     */
    public function execute(ExecutionKind $kind, callable $operation, ?ExecutionContextValues $values = null): ExecutionOutcome
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

        $context = new ExecutionContext($identifier, $kind, $values ?? new ExecutionContextValues());
        $primarySucceeded = false;
        $primaryResult = null;
        $primaryThrowable = null;
        $cleanupThrowable = null;
        $instrumentationFailures = [];
        $attachments = [];

        foreach ($this->executionContextAttachers as $attacher) {
            try {
                $attachments[] = $attacher->attach($context);
            } catch (Throwable $exception) {
                $instrumentationFailures[] = InstrumentationFailure::fromThrowable(
                    ObservationType::ExecutionStarted,
                    $exception,
                );
            }
        }

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

        $cleanupFailures = [];

        for ($index = count($attachments) - 1; $index >= 0; --$index) {
            try {
                $attachments[$index]->detach();
            } catch (Throwable $exception) {
                $cleanupFailures[] = $exception;
            }
        }

        try {
            $scope->close();
        } catch (Throwable $exception) {
            if ($cleanupFailures === []) {
                $cleanupThrowable = $exception;
            } else {
                $cleanupFailures[] = $exception;
            }
        }

        if ($cleanupFailures !== []) {
            $cleanupThrowable = new ExecutionCleanupFailed($cleanupFailures);
        }

        if ($cleanupThrowable !== null) {
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
        foreach ($this->observations->observeAll($observation) as $failure) {
            $instrumentationFailures[] = $failure;
        }
    }

    /**
     * @param array<array-key, mixed> $executionContextAttachers
     *
     * @return list<ExecutionContextAttacher>
     */
    private function normalizeExecutionContextAttachers(array $executionContextAttachers): array
    {
        $normalized = [];
        $seen = [];

        foreach ($executionContextAttachers as $attacher) {
            if (! $attacher instanceof ExecutionContextAttacher) {
                throw new InvalidArgumentException('Execution context attachers must implement ExecutionContextAttacher.');
            }

            $objectId = spl_object_id($attacher);

            if (isset($seen[$objectId])) {
                throw new InvalidArgumentException('Execution context attachers must not contain duplicate object instances.');
            }

            $seen[$objectId] = true;
            $normalized[] = $attacher;
        }

        return $normalized;
    }
}
