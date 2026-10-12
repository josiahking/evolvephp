<?php

declare(strict_types=1);

namespace Evolve\Mcp\Contribution;

use Evolve\Core\Component\ResolvedComponentGraph;
use Evolve\Mcp\Contribution\Exception\McpContributionFailed;
use Evolve\Mcp\Server\McpPromptDefinition;
use Evolve\Mcp\Server\McpResourceDefinition;
use Evolve\Mcp\Server\McpToolDefinition;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** Composes explicit bindings in Core-resolved order exactly once.
 * @experimental
 */
final class McpComponentContributionCoordinator
{
    private bool $started = false;

    /** @param iterable<mixed> $bindings */
    public function __construct(
        private ResolvedComponentGraph $graph,
        private iterable $bindings,
    ) {}

    /** @param array<mixed> $hostTools
     * @param array<mixed> $hostResources
     * @param array<mixed> $hostPrompts
     */
    public function contribute(array $hostTools = [], array $hostResources = [], array $hostPrompts = []): McpContributionCatalog
    {
        if ($this->started) {
            throw new LogicException('MCP component contribution coordinator has already run.');
        }
        $this->started = true;

        $bindings = $this->bindingsByDeclaration();
        $buffer = new McpContributionBuffer();
        $this->stageHost($buffer, $hostTools, McpToolDefinition::class, 'stageTool');
        $this->stageHost($buffer, $hostResources, McpResourceDefinition::class, 'stageResource');
        $this->stageHost($buffer, $hostPrompts, McpPromptDefinition::class, 'stagePrompt');

        foreach ($this->graph->orderedDeclarations() as $declaration) {
            $binding = $bindings[spl_object_id($declaration)] ?? null;
            if ($binding === null) {
                continue;
            }
            $registrar = new RestrictedMcpCapabilityRegistrar($buffer);
            try {
                $binding->contribute($registrar);
            } catch (Throwable $exception) {
                throw McpContributionFailed::forComponent($declaration->identifier(), $exception);
            } finally {
                $registrar->close();
            }
        }

        return $buffer->catalog();
    }

    /** @return array<int, McpComponentContribution> */
    private function bindingsByDeclaration(): array
    {
        $resolved = [];
        $identifiers = [];
        foreach ($this->graph->orderedDeclarations() as $declaration) {
            $resolved[spl_object_id($declaration)] = true;
            $identifiers[$declaration->identifier()->value()] = spl_object_id($declaration);
        }
        $bindings = [];
        foreach ($this->bindings as $binding) {
            if (!$binding instanceof McpComponentContribution) {
                throw new InvalidArgumentException('MCP bindings must contain McpComponentContribution instances.');
            }
            $declaration = $binding->declaration();
            $id = spl_object_id($declaration);
            $expected = $identifiers[$declaration->identifier()->value()] ?? null;
            if ($expected === null) {
                throw new InvalidArgumentException('MCP binding contains an inactive declaration.');
            }
            if ($expected !== $id || !isset($resolved[$id])) {
                throw new InvalidArgumentException('MCP binding must use the exact resolved declaration object.');
            }
            if (isset($bindings[$id])) {
                throw new InvalidArgumentException('MCP binding contains a duplicate declaration.');
            }
            $bindings[$id] = $binding;
        }

        return $bindings;
    }

    /** @param array<mixed> $definitions */
    private function stageHost(McpContributionBuffer $buffer, array $definitions, string $class, string $method): void
    {
        if (!array_is_list($definitions)) {
            throw new InvalidArgumentException('Host MCP definitions must be lists.');
        }
        foreach ($definitions as $definition) {
            if (!$definition instanceof $class) {
                throw new InvalidArgumentException('Host MCP definitions contain an invalid entry.');
            }
            $buffer->$method($definition);
        }
    }
}
