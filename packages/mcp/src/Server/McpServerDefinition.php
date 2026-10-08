<?php

declare(strict_types=1);

namespace Evolve\Mcp\Server;

use Mcp\Server;

final readonly class McpServerDefinition
{
    /**
     * @param list<McpToolDefinition> $tools
     * @param list<McpResourceDefinition> $resources
     * @param list<McpPromptDefinition> $prompts
     */
    public function __construct(
        private Server $server,
        private array $tools,
        private array $resources,
        private array $prompts,
    ) {}

    public function server(): Server
    {
        return $this->server;
    }

    /** @return list<McpToolDefinition> */
    public function tools(): array
    {
        return $this->tools;
    }

    /** @return list<McpResourceDefinition> */
    public function resources(): array
    {
        return $this->resources;
    }

    /** @return list<McpPromptDefinition> */
    public function prompts(): array
    {
        return $this->prompts;
    }
}
