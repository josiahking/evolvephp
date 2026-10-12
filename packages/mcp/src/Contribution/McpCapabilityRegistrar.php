<?php

declare(strict_types=1);

namespace Evolve\Mcp\Contribution;

use Evolve\Mcp\Server\McpPromptDefinition;
use Evolve\Mcp\Server\McpResourceDefinition;
use Evolve\Mcp\Server\McpToolDefinition;

/** Restricted, callback-scoped MCP capability registration boundary.
 * @experimental
 */
interface McpCapabilityRegistrar
{
    public function registerTool(McpToolDefinition $definition): void;
    public function registerResource(McpResourceDefinition $definition): void;
    public function registerPrompt(McpPromptDefinition $definition): void;
}
