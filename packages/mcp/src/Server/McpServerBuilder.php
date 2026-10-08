<?php

declare(strict_types=1);

namespace Evolve\Mcp\Server;

use Evolve\Mcp\Server\Exception\DuplicateMcpCapability;
use Evolve\Mcp\Server\Exception\InvalidMcpCapability;
use Evolve\Mcp\Server\Exception\McpServerFrozen;
use Mcp\Server\Builder;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\PromptHandlerInterface;
use Mcp\Server\Handler\ResourceHandlerInterface;
use Mcp\Server\Handler\ToolHandlerInterface;

final class McpServerBuilder
{
    /** @var array<string, McpToolDefinition> */
    private array $tools = [];

    /** @var array<string, McpResourceDefinition> */
    private array $resources = [];

    /** @var array<string, McpPromptDefinition> */
    private array $prompts = [];

    private ?McpServerDefinition $built = null;

    public function __construct(
        private readonly string $name,
        private readonly string $version,
        private readonly McpCapabilityInvoker $invoker,
    ) {
        if (trim($name) === '' || trim($name) !== $name || trim($version) === '' || trim($version) !== $version) {
            throw new InvalidMcpCapability('Invalid server name or version.');
        }
    }

    public function addTool(McpToolDefinition $definition): self
    {
        $this->assertMutable();
        if (isset($this->tools[$definition->name])) {
            throw new DuplicateMcpCapability('Duplicate tool name.');
        }
        $this->tools[$definition->name] = $definition;

        return $this;
    }

    public function addResource(McpResourceDefinition $definition): self
    {
        $this->assertMutable();
        if (isset($this->resources[$definition->uri])) {
            throw new DuplicateMcpCapability('Duplicate resource URI.');
        }
        $this->resources[$definition->uri] = $definition;

        return $this;
    }

    public function addPrompt(McpPromptDefinition $definition): self
    {
        $this->assertMutable();
        if (isset($this->prompts[$definition->name])) {
            throw new DuplicateMcpCapability('Duplicate prompt name.');
        }
        $this->prompts[$definition->name] = $definition;

        return $this;
    }

    public function build(): McpServerDefinition
    {
        if ($this->built !== null) {
            return $this->built;
        }

        $sdk = (new Builder())->setServerInfo($this->name, $this->version);

        foreach ($this->tools as $definition) {
            $serviceId = $definition->serviceId;
            $invoker = $this->invoker;
            $sdk->add($definition->sdkDefinition, new class ($invoker, $serviceId) implements ToolHandlerInterface {
                public function __construct(private McpCapabilityInvoker $invoker, private string $serviceId) {}
                public function execute(array $arguments, ClientGateway $gateway): mixed
                {
                    return $this->invoker->invoke($this->serviceId, $arguments);
                }
            });
        }
        foreach ($this->resources as $definition) {
            $serviceId = $definition->serviceId;
            $invoker = $this->invoker;
            $sdk->add($definition->sdkDefinition, new class ($invoker, $serviceId) implements ResourceHandlerInterface {
                public function __construct(private McpCapabilityInvoker $invoker, private string $serviceId) {}
                public function read(string $uri, ClientGateway $gateway): mixed
                {
                    return $this->invoker->invoke($this->serviceId, ['uri' => $uri]);
                }
            });
        }
        foreach ($this->prompts as $definition) {
            $serviceId = $definition->serviceId;
            $invoker = $this->invoker;
            $sdk->add($definition->sdkDefinition, new class ($invoker, $serviceId) implements PromptHandlerInterface {
                public function __construct(private McpCapabilityInvoker $invoker, private string $serviceId) {}
                public function get(array $arguments, ClientGateway $gateway): mixed
                {
                    return $this->invoker->invoke($this->serviceId, $arguments);
                }
            });
        }

        return $this->built = new McpServerDefinition(
            $sdk->build(),
            array_values($this->tools),
            array_values($this->resources),
            array_values($this->prompts),
        );
    }

    private function assertMutable(): void
    {
        if ($this->built !== null) {
            throw new McpServerFrozen('MCP server registration is frozen.');
        }
    }
}
