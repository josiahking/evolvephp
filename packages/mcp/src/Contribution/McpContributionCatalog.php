<?php

declare(strict_types=1);

namespace Evolve\Mcp\Contribution;

use Evolve\Mcp\Server\McpPromptDefinition;
use Evolve\Mcp\Server\McpResourceDefinition;
use Evolve\Mcp\Server\McpToolDefinition;

/** Immutable snapshot published only after contribution succeeds.
 * @experimental
 */
final readonly class McpContributionCatalog
{
    /** @param list<McpToolDefinition> $tools
     * @param list<McpResourceDefinition> $resources
     * @param list<McpPromptDefinition> $prompts
     */
    public function __construct(
        private array $tools,
        private array $resources,
        private array $prompts,
    ) {}

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
