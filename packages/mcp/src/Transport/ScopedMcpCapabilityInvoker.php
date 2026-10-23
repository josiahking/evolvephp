<?php

declare(strict_types=1);

namespace Evolve\Mcp\Transport;

use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Mcp\Observability\McpOperation;
use Evolve\Mcp\Observability\McpOperationDispatcher;
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
        private ?McpOperationDispatcher $operations = null,
        private string $transport = 'local',
    ) {
        if (!in_array($transport, ['http', 'stdio', 'local'], true)) {
            throw new \InvalidArgumentException('Invalid MCP transport category.');
        }
        $this->invokeInScope = \Closure::fromCallable($invokeInScope);
    }

    public function quarantineFailure(): ?\Throwable
    {
        return $this->quarantineFailure;
    }

    public function invoke(string $serviceId, array $arguments): mixed
    {
        $started = $this->operations?->isEnabled() ? hrtime(true) : null;
        $outcomeLabel = 'success';
        $failureCategory = null;
        try {
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
        } catch (\Throwable $exception) {
            $outcomeLabel = 'failure';
            $failureCategory = $this->quarantineFailure !== null ? 'cleanup' : 'operation';
            throw $exception;
        } finally {
            if ($started !== null) {
                try {
                    $this->operations->dispatch(new McpOperation(
                        'invoke',
                        $this->transport,
                        $outcomeLabel,
                        $serviceId,
                        durationNs: max(0, hrtime(true) - $started),
                        failureCategory: $failureCategory,
                    ));
                } catch (\Throwable) {
                    // Optional telemetry cannot replace the execution outcome.
                }
            }
        }
    }
}
