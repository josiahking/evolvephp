<?php

declare(strict_types=1);

namespace Evolve\Mcp\Transport;

use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Mcp\Server\McpCapabilityInvoker;

/**
 * Resolves each capability inside a fresh execution scope owned by the host.
 */
final class ScopedMcpCapabilityInvoker implements McpCapabilityInvoker
{
    /** @var \Closure(ExecutionScope, string, array<string, mixed>): mixed */
    private \Closure $invokeInScope;

    private ?\Throwable $quarantineFailure = null;

    /**
     * @param callable(ExecutionScope, string, array<string, mixed>): mixed $invokeInScope
     */
    public function __construct(
        private ExecutionOrchestrator $executions,
        callable $invokeInScope,
    ) {
        $this->invokeInScope = \Closure::fromCallable($invokeInScope);
    }

    public function quarantineFailure(): ?\Throwable
    {
        return $this->quarantineFailure;
    }

    public function invoke(string $serviceId, array $arguments): mixed
    {
        try {
            $outcome = $this->executions->execute(
                ExecutionKind::WorkerTask,
                fn($context, ExecutionScope $scope): mixed => ($this->invokeInScope)($scope, $serviceId, $arguments),
            );
        } catch (ExecutionStartFailed $exception) {
            $this->quarantineFailure = $exception;

            throw $exception;
        }

        if ($outcome->cleanupThrowable() !== null) {
            $this->quarantineFailure = $outcome->cleanupThrowable();

            throw $outcome->cleanupThrowable();
        }

        if ($outcome->primaryFailed()) {
            throw $outcome->primaryThrowableOrFail();
        }

        return $outcome->primaryResult();
    }
}
