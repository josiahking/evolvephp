<?php

declare(strict_types=1);

namespace Evolve\Core\Execution;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionStartFailed;
use Throwable;

final class ExecutionOrchestrator
{
    private bool $quarantined = false;

    public function __construct(private ServiceRegistry $services) {}

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

        try {
            $primaryResult = $operation($context, $scope);
            $primarySucceeded = true;
        } catch (Throwable $exception) {
            $primaryThrowable = $exception;
        }

        try {
            $scope->close();
        } catch (Throwable $exception) {
            $cleanupThrowable = $exception;
            $this->quarantined = true;
        } finally {
            unset($scope);
        }

        if ($primarySucceeded) {
            return ExecutionOutcome::succeeded($identifier, $kind, $primaryResult, $cleanupThrowable);
        }

        return ExecutionOutcome::failed($identifier, $kind, $primaryThrowable, $cleanupThrowable);
    }
}
