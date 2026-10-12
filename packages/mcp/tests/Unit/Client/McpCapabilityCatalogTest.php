<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Unit\Client;

use Evolve\Mcp\Client\McpCapabilityCatalog;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\Result\ListPromptsResult;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

final class McpCapabilityCatalogTest extends TestCase
{
    public function test_pagination_preserves_order_without_invocation(): void
    {
        $calls = [];
        $catalog = McpCapabilityCatalog::discover(
            static function (?string $cursor) use (&$calls): ListToolsResult {
                $calls[] = ['tools', $cursor];
                return $cursor === null
                    ? new ListToolsResult([new Tool('first', null, ['type' => 'object', 'properties' => [], 'required' => null], null, null)], 'next')
                    : new ListToolsResult([new Tool('second', null, ['type' => 'object', 'properties' => [], 'required' => null], null, null)]);
            },
            static function (?string $cursor) use (&$calls): ListResourcesResult {
                $calls[] = ['resources', $cursor];
                return new ListResourcesResult([new ResourceDefinition('app://guide', 'guide')]);
            },
            static function (?string $cursor) use (&$calls): ListPromptsResult {
                $calls[] = ['prompts', $cursor];
                return new ListPromptsResult([new Prompt('greeting')]);
            },
            3,
            5,
        );
        self::assertSame(['first', 'second'], array_map(static fn(Tool $tool): string => $tool->name, $catalog->tools()));
        self::assertSame('app://guide', $catalog->resources()[0]->uri);
        self::assertSame('greeting', $catalog->prompts()[0]->name);
        self::assertSame([['tools', null], ['tools', 'next'], ['resources', null], ['prompts', null]], $calls);
    }

    public function test_page_and_total_item_limits_fail_before_unbounded_listing(): void
    {
        $emptyResources = static fn(): ListResourcesResult => new ListResourcesResult([]);
        $emptyPrompts = static fn(): ListPromptsResult => new ListPromptsResult([]);
        foreach ([
            [static fn(): ListToolsResult => new ListToolsResult([], 'more'), 1, 5],
            [static fn(): ListToolsResult => new ListToolsResult([
                new Tool('first', null, ['type' => 'object', 'properties' => [], 'required' => null], null, null),
                new Tool('second', null, ['type' => 'object', 'properties' => [], 'required' => null], null, null),
            ]), 2, 1],
        ] as [$listing, $maxPages, $maxItems]) {
            try {
                McpCapabilityCatalog::discover($listing, $emptyResources, $emptyPrompts, $maxPages, $maxItems);
                self::fail('Listing limit was ignored.');
            } catch (\Evolve\Mcp\Client\McpClientException $exception) {
                self::assertSame(\Evolve\Mcp\Client\McpClientFailureCategory::Discovery, $exception->category);
            }
        }
    }
    public function test_cursor_cycles_and_duplicate_capabilities_are_rejected(): void
    {
        $emptyResources = static fn(): ListResourcesResult => new ListResourcesResult([]);
        $emptyPrompts = static fn(): ListPromptsResult => new ListPromptsResult([]);
        foreach ([
            static fn(?string $cursor): ListToolsResult => new ListToolsResult([], 'repeat'),
            static fn(?string $cursor): ListToolsResult => new ListToolsResult([new Tool('duplicate', null, ['type' => 'object', 'properties' => [], 'required' => null], null, null), new Tool('duplicate', null, ['type' => 'object', 'properties' => [], 'required' => null], null, null)]),
        ] as $listing) {
            try {
                McpCapabilityCatalog::discover($listing, $emptyResources, $emptyPrompts, 3, 5);
                self::fail('Malformed listing accepted.');
            } catch (\Evolve\Mcp\Client\McpClientException $exception) {
                self::assertSame(\Evolve\Mcp\Client\McpClientFailureCategory::Discovery, $exception->category);
            }
        }
    }
}
