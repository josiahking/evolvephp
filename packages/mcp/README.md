# EvolvePHP MCP

Optional experimental MCP package and official PHP MCP SDK dependency foundation for EvolvePHP 2.

`evolvephp/mcp` is an optional, experimental EvolvePHP 2 package requiring PHP `^8.4`. It composes an MCP server with the official PHP MCP SDK `mcp/sdk` at `^0.8.1`.

EvolvePHP 2 is pre-release. The canonical source is the EvolvePHP monorepo at https://github.com/josiahking/evolvephp; packages are not yet independently published. This package uses the BSD-3-Clause licence in `LICENSE.md`.

## Explicit server composition

Register each tool, resource and prompt with a stable identity and a host service ID. Definitions are immutable. The builder rejects invalid identities and duplicates, freezes registration on the first build, and returns the same server definition on repeated builds. Its declaration lists preserve registration order.

```php
use Evolve\Mcp\Server\McpCapabilityInvoker;
use Evolve\Mcp\Server\McpPromptDefinition;
use Evolve\Mcp\Server\McpResourceDefinition;
use Evolve\Mcp\Server\McpServerBuilder;
use Evolve\Mcp\Server\McpToolDefinition;
use Mcp\Schema\PromptArgument;

$invoker = new class implements McpCapabilityInvoker {
    public function invoke(string $serviceId, array $arguments): mixed
    {
        // Resolve and invoke the service in the host application's execution scope.
        return match ($serviceId) {
            'tools.echo' => $arguments['text'],
            'resources.guide' => 'Guide text',
            'prompts.greeting' => [
                ['role' => 'user', 'content' => [
                    'type' => 'text',
                    'text' => 'Hello ' . $arguments['name'],
                ]],
            ],
        };
    }
};

$builder = new McpServerBuilder('my-app', '1.0', $invoker);
$builder->addTool(new McpToolDefinition('echo', 'tools.echo', [
    'type' => 'object',
    'properties' => ['text' => ['type' => 'string']],
    'required' => ['text'],
]));
$builder->addResource(new McpResourceDefinition('app://guide', 'guide', 'resources.guide'));
$builder->addPrompt(new McpPromptDefinition(
    'greeting',
    'prompts.greeting',
    [new PromptArgument('name', required: true)],
));

$definition = $builder->build();
$sdkServer = $definition->server();
```

The host-supplied invoker runs on every tool call, resource read and prompt request. It receives tool and prompt arguments unchanged. Resource reads receive `['uri' => $requestedUri]`. The host is responsible for service resolution, execution scope, cleanup, authorization and policy. Composition does not resolve or cache host services.

The SDK owns MCP schema validation, protocol dispatch, errors and result formatting. The server definition exposes the SDK server, but does not start it or select a transport. Transport configuration is a later integration task. This Beta package does not yet provide STDIO or HTTP setup, remote clients, plugin or module contributions, or production authorization. It performs no filesystem discovery or global registration.
