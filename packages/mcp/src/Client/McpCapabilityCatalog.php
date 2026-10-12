<?php

declare(strict_types=1);

namespace Evolve\Mcp\Client;

use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\Result\ListPromptsResult;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;

/** A bounded, stable snapshot of untrusted remote declarations. */
final readonly class McpCapabilityCatalog
{
    /**
     * @param list<Tool> $tools
     * @param list<ResourceDefinition> $resources
     * @param list<Prompt> $prompts
     */
    private function __construct(
        private array $tools,
        private array $resources,
        private array $prompts,
    ) {}

    /**
     * @param callable(?string): ListToolsResult $listTools
     * @param callable(?string): ListResourcesResult $listResources
     * @param callable(?string): ListPromptsResult $listPrompts
     */
    public static function discover(
        callable $listTools,
        callable $listResources,
        callable $listPrompts,
        int $maxPages,
        int $maxItems,
    ): self {
        if ($maxPages < 1 || $maxItems < 1) {
            throw new \InvalidArgumentException('MCP catalog limits must be positive.');
        }
        $remaining = $maxItems;
        $tools = self::collect($listTools, ListToolsResult::class, 'tools', $maxPages, $remaining);
        $resources = self::collect($listResources, ListResourcesResult::class, 'resources', $maxPages, $remaining);
        $prompts = self::collect($listPrompts, ListPromptsResult::class, 'prompts', $maxPages, $remaining);

        return new self($tools, $resources, $prompts);
    }

    /**
     * @param callable(?string): (ListToolsResult|ListResourcesResult|ListPromptsResult) $list
     * @param class-string<ListToolsResult|ListResourcesResult|ListPromptsResult> $resultClass
     * @param int $remaining
     * @return array<int, Tool|ResourceDefinition|Prompt>
     */
    private static function collect(callable $list, string $resultClass, string $kind, int $maxPages, int &$remaining): array
    {
        $cursor = null;
        $seenCursors = [];
        $seenNames = [];
        $entries = [];
        for ($pageNumber = 0; $pageNumber < $maxPages; ++$pageNumber) {
            try {
                $page = $list($cursor);
            } catch (\Throwable) {
                throw new McpClientException(McpClientFailureCategory::Discovery, 'MCP capability listing failed.');
            }
            if (!$page instanceof $resultClass) {
                throw new McpClientException(McpClientFailureCategory::Discovery, 'MCP capability listing returned an invalid result.');
            }
            $items = $page->$kind;
            if (!is_array($items) || !array_is_list($items)) {
                throw new McpClientException(McpClientFailureCategory::Discovery, 'MCP capability listing is malformed.');
            }
            foreach ($items as $entry) {
                $identity = match ($kind) {
                    'tools' => $entry instanceof Tool ? $entry->name : null,
                    'resources' => $entry instanceof ResourceDefinition ? $entry->uri : null,
                    'prompts' => $entry instanceof Prompt ? $entry->name : null,
                    default => null,
                };
                if (!is_string($identity) || $identity === '' || isset($seenNames[$identity]) || --$remaining < 0) {
                    throw new McpClientException(McpClientFailureCategory::Discovery, 'MCP capability listing is duplicate or exceeds limits.');
                }
                $seenNames[$identity] = true;
                $entries[] = $entry;
            }
            $next = $page->nextCursor;
            if ($next === null) {
                return $entries;
            }
            if ($next === '' || isset($seenCursors[$next])) {
                throw new McpClientException(McpClientFailureCategory::Discovery, 'MCP capability cursor is invalid or cyclic.');
            }
            $seenCursors[$next] = true;
            $cursor = $next;
        }

        throw new McpClientException(McpClientFailureCategory::Discovery, 'MCP capability page limit exceeded.');
    }

    /** @return list<Tool> */
    public function tools(): array
    {
        return $this->tools;
    }

    /** @return list<ResourceDefinition> */
    public function resources(): array
    {
        return $this->resources;
    }

    /** @return list<Prompt> */
    public function prompts(): array
    {
        return $this->prompts;
    }
}
