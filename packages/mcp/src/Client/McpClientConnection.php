<?php

declare(strict_types=1);

namespace Evolve\Mcp\Client;

use Mcp\Client;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Schema\Result\ReadResourceResult;
use Throwable;

/** One SDK connection. Failed operations poison this instance until it is closed. */
final class McpClientConnection
{
    private bool $closed = false;
    private bool $poisoned = false;
    private ?McpClientException $quarantineFailure = null;

    public function __construct(
        private Client $client,
        private McpClientConfiguration $configuration,
    ) {}

    public function isReusable(): bool
    {
        return !$this->closed && !$this->poisoned;
    }

    public function quarantineFailure(): ?McpClientException
    {
        return $this->quarantineFailure;
    }

    public function discover(): McpCapabilityCatalog
    {
        return $this->operation(
            McpClientFailureCategory::Discovery,
            fn(): McpCapabilityCatalog => McpCapabilityCatalog::discover(
                $this->client->listTools(...),
                $this->client->listResources(...),
                $this->client->listPrompts(...),
                $this->configuration->maxPages,
                $this->configuration->maxItems,
            ),
        );
    }

    /** @param array<string, mixed> $arguments */
    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        $this->assertIdentity($name);
        return $this->operation(McpClientFailureCategory::Operation, fn(): CallToolResult => $this->client->callTool($name, $arguments));
    }

    public function readResource(string $uri): ReadResourceResult
    {
        $this->assertIdentity($uri);
        return $this->operation(McpClientFailureCategory::Operation, fn(): ReadResourceResult => $this->client->readResource($uri));
    }

    /** @param array<string, string> $arguments */
    public function getPrompt(string $name, array $arguments = []): GetPromptResult
    {
        $this->assertIdentity($name);
        return $this->operation(McpClientFailureCategory::Operation, fn(): GetPromptResult => $this->client->getPrompt($name, $arguments));
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        try {
            $this->client->disconnect();
        } catch (Throwable) {
            $this->poisoned = true;
            $this->quarantineFailure = new McpClientException(
                McpClientFailureCategory::Cleanup,
                'MCP connection cleanup failed; recycle the worker.',
            );
            throw $this->quarantineFailure;
        }
    }

    private function assertIdentity(string $identity): void
    {
        if ($identity === '' || strlen($identity) > 2048 || str_contains($identity, "\0")) {
            throw new McpClientException(McpClientFailureCategory::Configuration, 'MCP capability identity is invalid.');
        }
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function operation(McpClientFailureCategory $category, callable $operation): mixed
    {
        if (!$this->isReusable()) {
            throw new McpClientException(McpClientFailureCategory::Unusable, 'MCP connection is not reusable.');
        }
        try {
            return $operation();
        } catch (Throwable $exception) {
            $this->poisoned = true;
            if ($exception instanceof McpClientException) {
                throw $exception;
            }
            throw new McpClientException($category, 'MCP remote operation failed.');
        }
    }
}
