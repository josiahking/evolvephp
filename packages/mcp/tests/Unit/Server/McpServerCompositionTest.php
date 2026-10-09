<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Unit\Server;

use Evolve\Mcp\Server\McpCapabilityInvoker;
use Evolve\Mcp\Server\McpPromptDefinition;
use Evolve\Mcp\Server\McpResourceDefinition;
use Evolve\Mcp\Server\McpServerBuilder;
use Evolve\Mcp\Server\McpToolDefinition;
use PHPUnit\Framework\TestCase;

final class McpServerCompositionTest extends TestCase
{
    public function test_explicit_capabilities_are_composed_in_registration_order(): void
    {
        $invoker = new class implements McpCapabilityInvoker {
            public function invoke(string $serviceId, array $arguments): mixed
            {
                throw new \LogicException('Composition must not invoke a service.');
            }
        };

        $builder = new McpServerBuilder('example', '1.0', $invoker);
        $builder->addTool(new McpToolDefinition('first', 'tool.first', ['type' => 'object']))
            ->addTool(new McpToolDefinition('second', 'tool.second', ['type' => 'object']))
            ->addResource(new McpResourceDefinition('example://first', 'First', 'resource.first'))
            ->addPrompt(new McpPromptDefinition('greeting', 'prompt.greeting'));

        $definition = $builder->build();

        self::assertSame($definition->server(), $builder->build()->server());
        self::assertSame($invoker, $definition->invoker());
        self::assertInstanceOf(\Mcp\Server\Stateless\StatelessProtocol::class, $definition->statelessProtocol());
        self::assertSame($definition->statelessProtocol(), $builder->build()->statelessProtocol());
        self::assertSame(['first', 'second'], array_map(static fn(McpToolDefinition $tool): string => $tool->name, $definition->tools()));
        self::assertSame(['example://first'], array_map(static fn(McpResourceDefinition $resource): string => $resource->uri, $definition->resources()));
        self::assertSame(['greeting'], array_map(static fn(McpPromptDefinition $prompt): string => $prompt->name, $definition->prompts()));
        self::assertSame($definition, $builder->build());
    }
}
