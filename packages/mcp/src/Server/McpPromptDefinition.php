<?php

declare(strict_types=1);

namespace Evolve\Mcp\Server;

use Evolve\Mcp\Server\Exception\InvalidMcpCapability;
use Mcp\Schema\Prompt;
use Mcp\Schema\PromptArgument;

final readonly class McpPromptDefinition
{
    public Prompt $sdkDefinition;

    /** @param list<PromptArgument> $arguments */
    public function __construct(
        public string $name,
        public string $serviceId,
        public array $arguments = [],
        public ?string $description = null,
        public ?string $title = null,
    ) {
        McpToolDefinition::assertIdentifier($name, 'prompt name');
        McpToolDefinition::assertServiceId($serviceId);
        $seen = [];
        foreach ($arguments as $value) {
            $argument = self::validatedArgument($value);
            McpToolDefinition::assertIdentifier($argument->name, 'prompt argument name');
            if (isset($seen[$argument->name])) {
                throw new InvalidMcpCapability('Duplicate prompt argument.');
            }
            $seen[$argument->name] = true;
        }

        $this->sdkDefinition = new Prompt($name, $title, $description, $arguments);
    }

    private static function validatedArgument(mixed $value): PromptArgument
    {
        if (!$value instanceof PromptArgument) {
            throw new InvalidMcpCapability('Prompt arguments must be SDK PromptArgument objects.');
        }

        return $value;
    }
}
