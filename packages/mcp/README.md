# EvolvePHP MCP

Optional experimental MCP server composition, STDIO and restricted JSON-only HTTP integration for EvolvePHP 2.

`evolvephp/mcp` is an optional, experimental EvolvePHP 2 package requiring PHP `^8.4`. It composes an MCP server with the official PHP MCP SDK `mcp/sdk` at `^0.8.1`. Runtime dependencies are `evolvephp/core`, `mcp/sdk`, `psr/http-factory`, `psr/http-message` and `psr/http-server-handler`.

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

The SDK owns MCP schema validation, protocol dispatch, errors and result formatting. The server definition exposes the SDK server and modern stateless protocol. It performs no filesystem discovery or global registration.

## STDIO transport

`McpStdioCommand` is an explicit `mcp:serve` Core command. Construct it with the server definition, the same `ScopedMcpCapabilityInvoker` used by the builder, and host-opened input and protocol-output streams. Mismatched invokers are rejected. Register it in a Core `CommandRegistry` and run it through `CommandRunner` or `CliApplication`. The SDK owns and closes the protocol streams; do not use the ordinary `CommandOutput::write()` channel for diagnostics. The command writes operational failures to `writeError()` and returns a nonzero exit code if capability cleanup fails or the invoker cannot start another execution safely. Each capability invocation runs through a separate `ExecutionOrchestrator` operation, beyond the command's outer CLI scope.

The SDK STDIO transport serves handshake-era MCP. The integration test verifies `2025-06-18` initialization and tool, resource and prompt dispatch. It does not claim modern `2026-07-28` STDIO support.

## Restricted JSON-only HTTP endpoint

`McpHttpRequestHandler` is an opt-in PSR-15 handler. Build the server definition with `ScopedMcpCapabilityInvoker`, then supply it with PSR-17 response and stream factories and a required host-owned authorization callable. Mount the handler explicitly with `Route`, `RoutingRequestHandler` and `HttpKernel`. The host authorization callback must return the boolean `true` for each permitted request; SDK Origin and Host checks are an additional protection. The SDK default Host allowlist is local only (`localhost`, `127.0.0.1`, `[::1]`); this adapter does not expose remote-host configuration.

The handler uses the SDK's modern stateless HTTP transport and protocol for `2026-07-28` JSON responses and bodyless notifications. It bounds request bodies, retains SDK CORS and DNS-rebinding middleware, and rejects requests advertising `text/event-stream` or invoking `subscriptions/listen` before SDK dispatch. A returned SDK callback stream is rejected without consuming it. It provides no legacy HTTP session, GET subscription, DELETE teardown, SSE or cross-worker persistence. Do not mount it as an unrestricted Streamable HTTP endpoint. The host owns response emission and must inspect the `HttpKernel` execution outcome before transmission. The handler applies the SDK CORS and DNS-rebinding middleware before all response branches, including restricted-endpoint refusals. If an inner capability execution requires quarantine, the handler throws a terminal runtime exception after SDK dispatch and refuses later requests. The host must inspect `McpHttpRequestHandler::quarantineFailure()` after each `HttpKernel` outcome and recycle an affected worker. A failed outer primary outcome is an immediate signal; `HttpKernel`'s outer `isReusable()` value alone does not encode inner capability cleanup state. Ordinary capability exceptions with successful cleanup remain SDK JSON-RPC errors.

Full SSE lifecycle management is deferred to a separately tracked roadmap item. No HTTP emitter or persistent session store is supplied here.