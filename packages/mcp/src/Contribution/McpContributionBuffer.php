<?php

declare(strict_types=1);

namespace Evolve\Mcp\Contribution;

use Evolve\Mcp\Server\Exception\DuplicateMcpCapability;
use Evolve\Mcp\Server\McpPromptDefinition;
use Evolve\Mcp\Server\McpResourceDefinition;
use Evolve\Mcp\Server\McpToolDefinition;

/** @internal Stages definitions by capability kind before publication. */
final class McpContributionBuffer
{
    /** @var array<string, McpToolDefinition> */
    private array $tools = [];
    /** @var array<string, McpResourceDefinition> */
    private array $resources = [];
    /** @var array<string, McpPromptDefinition> */
    private array $prompts = [];

    public function stageTool(McpToolDefinition $definition): void
    {
        if (isset($this->tools[$definition->name])) {
            throw new DuplicateMcpCapability('Duplicate tool name.');
        }
        $this->tools[$definition->name] = $definition;
    }

    public function stageResource(McpResourceDefinition $definition): void
    {
        if (isset($this->resources[$definition->uri])) {
            throw new DuplicateMcpCapability('Duplicate resource URI.');
        }
        $this->resources[$definition->uri] = $definition;
    }

    public function stagePrompt(McpPromptDefinition $definition): void
    {
        if (isset($this->prompts[$definition->name])) {
            throw new DuplicateMcpCapability('Duplicate prompt name.');
        }
        $this->prompts[$definition->name] = $definition;
    }

    public function catalog(): McpContributionCatalog
    {
        return new McpContributionCatalog(array_values($this->tools), array_values($this->resources), array_values($this->prompts));
    }
}
