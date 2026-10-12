<?php

declare(strict_types=1);

namespace Evolve\Mcp\Client;

use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Secret\Contracts\SecretResolver;
use Throwable;

/** Opens and disposes of one client connection inside each Core execution scope. */
final class ScopedMcpClient
{
    private ?McpClientException $quarantineFailure = null;

    public function __construct(
        private ExecutionOrchestrator $executions,
        private McpClientFactory $factory,
        private ?SecretResolver $secrets = null,
    ) {}

    public function quarantineFailure(): ?McpClientException
    {
        return $this->quarantineFailure;
    }

    /** @param callable(McpClientConnection, ExecutionScope): mixed $operation */
    public function run(McpClientConfiguration $configuration, callable $operation): mixed
    {
        if ($this->quarantineFailure !== null) {
            throw new McpClientException(McpClientFailureCategory::Unusable, 'MCP client worker requires quarantine.');
        }

        $connectionCleanup = null;
        try {
            $outcome = $this->executions->execute(
                ExecutionKind::WorkerTask,
                function ($context, ExecutionScope $scope) use ($configuration, $operation, &$connectionCleanup): mixed {
                    $connection = $this->factory->connect($configuration, $this->secrets);
                    try {
                        return $operation($connection, $scope);
                    } catch (Throwable $exception) {
                        if ($exception instanceof McpClientException) {
                            throw $exception;
                        }
                        throw new McpClientException(McpClientFailureCategory::Operation, 'MCP client operation failed.');
                    } finally {
                        try {
                            $connection->close();
                        } catch (McpClientException $exception) {
                            $connectionCleanup = $exception;
                        }
                    }
                },
            );
        } catch (ExecutionStartFailed) {
            $this->quarantineFailure = new McpClientException(
                McpClientFailureCategory::Unusable,
                'MCP client execution could not start; recycle the worker.',
                McpClientFailureCategory::Cleanup,
            );
            throw $this->quarantineFailure;
        }

        $primary = $outcome->primaryThrowable();
        if ($connectionCleanup !== null || $outcome->cleanupThrowable() !== null
            || ($primary instanceof McpClientException && $primary->cleanupCategory !== null)) {
            $category = $primary instanceof McpClientException ? $primary->category : McpClientFailureCategory::Cleanup;
            $this->quarantineFailure = new McpClientException(
                $category,
                'MCP client execution cleanup failed; recycle the worker.',
                McpClientFailureCategory::Cleanup,
            );
            throw $this->quarantineFailure;
        }
        if ($primary !== null) {
            if ($primary instanceof McpClientException) {
                throw $primary;
            }
            throw new McpClientException(McpClientFailureCategory::Operation, 'MCP client operation failed.');
        }

        return $outcome->primaryResult();
    }
}
