<?php

declare(strict_types=1);

namespace Evolve\Mcp\Contribution;

use Evolve\Core\Component\ResolvedComponentGraph;
use Evolve\Mcp\Server\McpCapabilityInvoker;
use Evolve\Mcp\Server\McpServerBuilder;
use Evolve\Mcp\Server\McpServerDefinition;

/** Explicit, atomic MCP server composition from a resolved graph.
 * @experimental
 */
final readonly class McpContributionServerComposer
{
    public function __construct(
        private string $name,
        private string $version,
        private McpCapabilityInvoker $invoker,
    ) {}

    /** @param iterable<mixed> $bindings
     * @param array<mixed> $hostTools
     * @param array<mixed> $hostResources
     * @param array<mixed> $hostPrompts
     */
    public function compose(
        ResolvedComponentGraph $graph,
        iterable $bindings,
        array $hostTools = [],
        array $hostResources = [],
        array $hostPrompts = [],
    ): McpServerDefinition {
        $catalog = (new McpComponentContributionCoordinator($graph, $bindings))
            ->contribute($hostTools, $hostResources, $hostPrompts);
        $builder = new McpServerBuilder($this->name, $this->version, $this->invoker);
        foreach ($catalog->tools() as $definition) {
            $builder->addTool($definition);
        }
        foreach ($catalog->resources() as $definition) {
            $builder->addResource($definition);
        }
        foreach ($catalog->prompts() as $definition) {
            $builder->addPrompt($definition);
        }

        return $builder->build();
    }
}
