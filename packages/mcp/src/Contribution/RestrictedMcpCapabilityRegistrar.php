<?php

declare(strict_types=1);

namespace Evolve\Mcp\Contribution;

use Evolve\Mcp\Server\McpPromptDefinition;
use Evolve\Mcp\Server\McpResourceDefinition;
use Evolve\Mcp\Server\McpToolDefinition;
use LogicException;

/** @internal Active only for the current contribution callback. */
final class RestrictedMcpCapabilityRegistrar implements McpCapabilityRegistrar
{
    private ?McpContributionBuffer $buffer;

    public function __construct(McpContributionBuffer $buffer)
    {
        $this->buffer = $buffer;
    }

    public function registerTool(McpToolDefinition $definition): void
    {
        $this->active()->stageTool($definition);
    }

    public function registerResource(McpResourceDefinition $definition): void
    {
        $this->active()->stageResource($definition);
    }

    public function registerPrompt(McpPromptDefinition $definition): void
    {
        $this->active()->stagePrompt($definition);
    }

    public function close(): void
    {
        $this->buffer = null;
    }

    private function active(): McpContributionBuffer
    {
        return $this->buffer ?? throw new LogicException('MCP capability registrar is no longer active.');
    }
}
