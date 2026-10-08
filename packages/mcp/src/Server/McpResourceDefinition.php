<?php

declare(strict_types=1);

namespace Evolve\Mcp\Server;

use Evolve\Mcp\Server\Exception\InvalidMcpCapability;
use Mcp\Schema\ResourceDefinition;

final readonly class McpResourceDefinition
{
    public ResourceDefinition $sdkDefinition;

    public function __construct(
        public string $uri,
        public string $name,
        public string $serviceId,
        public ?string $description = null,
        public ?string $mimeType = null,
        public ?string $title = null,
    ) {
        if ($uri === '' || trim($uri) !== $uri) {
            throw new InvalidMcpCapability('Invalid resource URI.');
        }
        McpToolDefinition::assertIdentifier($name, 'resource name');
        McpToolDefinition::assertServiceId($serviceId);

        try {
            $this->sdkDefinition = new ResourceDefinition($uri, $name, $title, $description, $mimeType);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidMcpCapability('Invalid resource URI.', previous: $exception);
        }
    }
}
