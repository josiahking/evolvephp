<?php

declare(strict_types=1);

namespace Evolve\Mcp\Server;

use Evolve\Mcp\Server\Exception\InvalidMcpCapability;
use Mcp\Schema\Tool;

final readonly class McpToolDefinition
{
    public Tool $sdkDefinition;

    /** @param array<string, mixed> $inputSchema */
    public function __construct(
        public string $name,
        public string $serviceId,
        array $inputSchema,
        public ?string $description = null,
        public ?string $title = null,
    ) {
        self::assertIdentifier($name, 'tool name');
        self::assertServiceId($serviceId);

        try {
            $this->sdkDefinition = new Tool($name, $title, $inputSchema, $description, null);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidMcpCapability('Invalid tool schema.', previous: $exception);
        }
    }

    public static function assertIdentifier(string $value, string $label): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/D', $value)) {
            throw new InvalidMcpCapability("Invalid {$label}.");
        }
    }

    public static function assertServiceId(string $serviceId): void
    {
        if ($serviceId === '' || trim($serviceId) !== $serviceId) {
            throw new InvalidMcpCapability('Invalid service ID.');
        }
    }
}
