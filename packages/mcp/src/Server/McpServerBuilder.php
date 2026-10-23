<?php

declare(strict_types=1);

namespace Evolve\Mcp\Server;

use Evolve\Mcp\Observability\McpOperation;
use Evolve\Mcp\Observability\McpOperationDispatcher;
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

    /** @var \Closure(McpCapabilityKind, string): bool|null */
    private ?\Closure $guard;

    public function __construct(
        private readonly string $name,
        private readonly string $version,
        private readonly McpCapabilityInvoker $invoker,
        ?callable $guard = null,
        private readonly ?McpOperationDispatcher $operations = null,
    ) {
        $this->guard = $guard === null ? null : \Closure::fromCallable($guard);
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
        $this->recordRegistration($definition->name);

        return $this;
    }

    public function addResource(McpResourceDefinition $definition): self
    {
        $this->assertMutable();
        if (isset($this->resources[$definition->uri])) {
            throw new DuplicateMcpCapability('Duplicate resource URI.');
        }
        $this->resources[$definition->uri] = $definition;
        $this->recordRegistration($definition->uri);

        return $this;
    }

    public function addPrompt(McpPromptDefinition $definition): self
    {
        $this->assertMutable();
        if (isset($this->prompts[$definition->name])) {
            throw new DuplicateMcpCapability('Duplicate prompt name.');
        }
        $this->prompts[$definition->name] = $definition;
        $this->recordRegistration($definition->name);

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
            $sdk->add($definition->sdkDefinition, new class ($invoker, $serviceId, $this->guard, $definition->name) implements ToolHandlerInterface {
                public function __construct(private McpCapabilityInvoker $invoker, private string $serviceId, private ?\Closure $guard, private string $name) {}
                public function execute(array $arguments, ClientGateway $gateway): mixed
                {
                    if ($this->guard !== null && ($this->guard)(McpCapabilityKind::Tool, $this->name) !== true) {
                        throw new \RuntimeException('MCP capability unavailable.');
                    }

                    return $this->invoker->invoke($this->serviceId, $arguments);
                }
            });
        }
        foreach ($this->resources as $definition) {
            $serviceId = $definition->serviceId;
            $invoker = $this->invoker;
            $sdk->add($definition->sdkDefinition, new class ($invoker, $serviceId, $this->guard, $definition->uri) implements ResourceHandlerInterface {
                public function __construct(private McpCapabilityInvoker $invoker, private string $serviceId, private ?\Closure $guard, private string $uri) {}
                public function read(string $uri, ClientGateway $gateway): mixed
                {
                    if ($this->guard !== null && ($this->guard)(McpCapabilityKind::Resource, $this->uri) !== true) {
                        throw new \RuntimeException('MCP capability unavailable.');
                    }

                    return $this->invoker->invoke($this->serviceId, ['uri' => $uri]);
                }
            });
        }
        foreach ($this->prompts as $definition) {
            $serviceId = $definition->serviceId;
            $invoker = $this->invoker;
            $sdk->add($definition->sdkDefinition, new class ($invoker, $serviceId, $this->guard, $definition->name) implements PromptHandlerInterface {
                public function __construct(private McpCapabilityInvoker $invoker, private string $serviceId, private ?\Closure $guard, private string $name) {}
                public function get(array $arguments, ClientGateway $gateway): mixed
                {
                    if ($this->guard !== null && ($this->guard)(McpCapabilityKind::Prompt, $this->name) !== true) {
                        throw new \RuntimeException('MCP capability unavailable.');
                    }

                    return $this->invoker->invoke($this->serviceId, $arguments);
                }
            });
        }

        return $this->built = new McpServerDefinition(
            $sdk->build(),
            array_values($this->tools),
            array_values($this->resources),
            array_values($this->prompts),
            $sdk->buildStateless(),
            $this->invoker,
            $this->name,
            $this->version,
        );
    }

    private function recordRegistration(string $identity): void
    {
        if ($this->operations?->isEnabled()) {
            $this->operations->dispatch(new McpOperation('register', 'local', 'success', $identity));
        }
    }

    private function assertMutable(): void
    {
        if ($this->built !== null) {
            throw new McpServerFrozen('MCP server registration is frozen.');
        }
    }
}
