<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Unit\Contribution;

use Evolve\Mcp\Contribution\McpContributionBuffer;
use Evolve\Mcp\Contribution\RestrictedMcpCapabilityRegistrar;
use Evolve\Mcp\Server\Exception\DuplicateMcpCapability;
use Evolve\Mcp\Server\McpPromptDefinition;
use Evolve\Mcp\Server\McpResourceDefinition;
use Evolve\Mcp\Server\McpToolDefinition;
use LogicException;
use PHPUnit\Framework\TestCase;

final class McpCapabilityRegistrarTest extends TestCase
{
    public function test_staging_keeps_order_and_separates_identity_kinds(): void
    {
        $buffer = new McpContributionBuffer();
        $registrar = new RestrictedMcpCapabilityRegistrar($buffer);
        $registrar->registerTool(new McpToolDefinition('same', 'one', ['type' => 'object']));
        $registrar->registerTool(new McpToolDefinition('next', 'two', ['type' => 'object']));
        $registrar->registerResource(new McpResourceDefinition('example://same', 'same', 'three'));
        $registrar->registerPrompt(new McpPromptDefinition('same', 'four'));
        $catalog = $buffer->catalog();
        self::assertSame(['same', 'next'], array_map(static fn(McpToolDefinition $tool): string => $tool->name, $catalog->tools()));
        self::assertCount(1, $catalog->resources());
        self::assertCount(1, $catalog->prompts());
        $registrar->close();
        $this->expectException(LogicException::class);
        $registrar->registerTool(new McpToolDefinition('late', 'late', ['type' => 'object']));
    }

    public function test_duplicate_names_and_uris_are_rejected(): void
    {
        foreach ([
            ['registerTool', new McpToolDefinition('same', 'one', ['type' => 'object']), new McpToolDefinition('same', 'two', ['type' => 'object'])],
            ['registerResource', new McpResourceDefinition('example://same', 'one', 'one'), new McpResourceDefinition('example://same', 'two', 'two')],
            ['registerPrompt', new McpPromptDefinition('same', 'one'), new McpPromptDefinition('same', 'two')],
        ] as [$method, $first, $second]) {
            $buffer = new McpContributionBuffer();
            $registrar = new RestrictedMcpCapabilityRegistrar($buffer);
            $registrar->$method($first);
            try {
                $registrar->$method($second);
                self::fail('Expected duplicate rejection.');
            } catch (DuplicateMcpCapability) {
                self::assertSame($first, match ($method) {
                    'registerTool' => $buffer->catalog()->tools()[0],
                    'registerResource' => $buffer->catalog()->resources()[0],
                    default => $buffer->catalog()->prompts()[0],
                });
            }
        }
    }
}
