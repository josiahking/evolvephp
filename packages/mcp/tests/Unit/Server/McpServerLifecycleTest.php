<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Unit\Server;

use Evolve\Mcp\Server\Exception\DuplicateMcpCapability;
use Evolve\Mcp\Server\Exception\InvalidMcpCapability;
use Evolve\Mcp\Server\Exception\McpServerFrozen;
use Evolve\Mcp\Server\McpCapabilityInvoker;
use Evolve\Mcp\Server\McpPromptDefinition;
use Evolve\Mcp\Server\McpResourceDefinition;
use Evolve\Mcp\Server\McpServerBuilder;
use Evolve\Mcp\Server\McpToolDefinition;
use PHPUnit\Framework\TestCase;

final class McpServerLifecycleTest extends TestCase
{
    private function builder(): McpServerBuilder
    {
        return new McpServerBuilder('example', '1.0', new class implements McpCapabilityInvoker {
            public function invoke(string $serviceId, array $arguments): mixed
            {
                return null;
            }
        });
    }

    public function test_duplicate_names_and_uris_are_rejected_without_replacement(): void
    {
        foreach ([
            [new McpToolDefinition('same', 'one', ['type' => 'object']), new McpToolDefinition('same', 'two', ['type' => 'object']), 'addTool'],
            [new McpResourceDefinition('example://same', 'same', 'one'), new McpResourceDefinition('example://same', 'other', 'two'), 'addResource'],
            [new McpPromptDefinition('same', 'one'), new McpPromptDefinition('same', 'two'), 'addPrompt'],
        ] as [$first, $second, $method]) {
            $builder = $this->builder();
            $builder->$method($first);
            try {
                $builder->$method($second);
                self::fail('Expected duplicate rejection.');
            } catch (DuplicateMcpCapability) {
                self::assertSame($first, match ($method) {
                    'addTool' => $builder->build()->tools()[0],
                    'addResource' => $builder->build()->resources()[0],
                    default => $builder->build()->prompts()[0],
                });
            }
        }
    }

    public function test_invalid_definitions_fail_before_build(): void
    {
        foreach ([
            static fn() => new McpToolDefinition('', 'service', ['type' => 'object']),
            static fn() => new McpToolDefinition('tool name', 'service', ['type' => 'object']),
            static fn() => new McpToolDefinition('tool', '', ['type' => 'object']),
            static fn() => new McpToolDefinition('tool', 'service', ['type' => 'string']),
            static fn() => new McpResourceDefinition('invalid', 'resource', 'service'),
            static fn() => new McpPromptDefinition('', 'service'),
            static fn() => new McpServerBuilder('', '1.0', new class implements McpCapabilityInvoker {
                public function invoke(string $serviceId, array $arguments): mixed
                {
                    return null;
                }
            }),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Expected invalid definition rejection.');
            } catch (InvalidMcpCapability $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_build_is_idempotent_and_freezes_registration(): void
    {
        $builder = $this->builder();
        $definition = $builder->build();
        self::assertSame($definition, $builder->build());
        $this->expectException(McpServerFrozen::class);
        $builder->addPrompt(new McpPromptDefinition('later', 'service'));
    }
}
