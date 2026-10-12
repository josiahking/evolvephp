# EvolvePHP MCP

Optional experimental MCP server, transport and explicitly configured client foundation for EvolvePHP 2.

`evolvephp/mcp` is an optional, experimental EvolvePHP 2 package requiring PHP `^8.4`. It composes MCP server and client facilities with the official PHP MCP SDK `mcp/sdk` at `^0.8.1`. Runtime dependencies are `evolvephp/core`, `evolvephp/secret-contracts`, `mcp/sdk`, `psr/http-client`, `psr/http-factory`, `psr/http-message` and `psr/http-server-handler`.

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
## Explicit client foundation

Construct an HTTP configuration with an HTTPS endpoint, a caller-owned PSR-18 client, PSR-17 request and stream factories, and an approval callback for the exact endpoint. The caller must configure the underlying client to enforce TLS, destination and SSRF policy, no automatic redirects, and concrete connection/read deadlines. The adapter rechecks the endpoint before each send; it cannot inspect hidden redirects performed by the injected client.

```php
use Evolve\Mcp\Client\McpClientConfiguration;
use Evolve\Mcp\Client\McpClientFactory;

// $http is a PSR-18 client configured by the application with TLS, no redirects,
// destination controls and network deadlines. $factory supplies PSR-17 factories.
$config = McpClientConfiguration::http(
    'https://localhost/mcp',
    $http,
    $factory,
    $factory,
    static fn (string $url): bool => $url === 'https://localhost/mcp',
);
$connection = (new McpClientFactory())->connect($config);
try {
    $catalog = $connection->discover(); // no capability is invoked
    $result = $connection->callTool('echo', ['text' => 'hello']);
} finally {
    $connection->close();
}
```

A named bearer secret can be supplied as a `SecretName` argument to `McpClientConfiguration::http()`; pass a `SecretResolver` to `McpClientFactory::connect()`. Resolution occurs before network activity on every connection and does not cache secret values. The SDK receives a fresh client and transport, the configured protocol version (`2026-07-28` for HTTP), zero connection retries, bounded SDK logical timeouts and its default `NullLogger`. The injected PSR-18 client must enforce actual I/O deadlines; the SDK's synchronous blocking I/O cannot always be interrupted. Protocol-level reissues may occur, so tool effects are not guaranteed exactly once.

The HTTP wrapper replaces the SDK's SSE-capable Accept header with `application/json`. It rejects response session headers, redirects, SSE or unexpected content types, and reads response bodies within a byte limit even when Content-Length is absent or false. The endpoint must be HTTPS and must contain no URL userinfo, query or fragment. HTTP supports only stateless JSON responses from the accepted restricted server. No SSE stream, session or automatic trust elevation is supported.

Client STDIO is supported on Linux PHP only for EvolvePHP 2.0 Beta. Native Windows PHP refuses STDIO at connection creation before secret resolution or child launch; HTTP client mode remains available on Windows. Native Windows PHP STDIO is deferred to the 2.1 follow-up. On Linux, use `McpClientConfiguration::stdio()` with an absolute existing executable path, explicit arguments, working directory and environment, plus an application-owned approval callback. The default protocol is `2025-06-18`. Named environment secrets can be resolved per connection. The child receives only the explicitly configured environment and resolved named secrets; an empty environment stays empty and does not inherit ambient parent credentials. Only trusted local application configuration may provide the command; remote capability declarations never become commands. The SDK owns the child process and pipes. Startup and initialization errors become categorized failures. The SDK attempts termination on close, but termination of an unresponsive child is not guaranteed.

`McpClientConnection::discover()` lists tools, resources and prompts through the SDK with page, item, duplicate and cursor-cycle limits. Results retain SDK types and listing order. Invoke only explicitly chosen capabilities through `callTool()`, `readResource()` and `getPrompt()`. A failed operation poisons that connection; close it and construct a new one. `McpClientException::category` exposes safe categories without raw protocol content or credentials. If cleanup is uncertain, `cleanupCategory` identifies it and the host must recycle the affected worker.

`ScopedMcpClient::run()` is the Core execution-scoped option. It resolves credentials, opens a fresh connection, invokes the caller callback and closes the connection for each run. It exposes `quarantineFailure()` if cleanup or execution isolation fails. The wrapper forwards no identity, tenant, cookies, authorization or other execution context by default. The application controls any deliberate, bounded propagation.