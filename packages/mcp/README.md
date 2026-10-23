# EvolvePHP MCP

Optional experimental MCP server, transport, component contribution and explicitly configured client foundation for EvolvePHP 2.

`evolvephp/mcp` is an optional, experimental EvolvePHP 2 package requiring PHP `^8.4`. It composes MCP server and client facilities with the official PHP MCP SDK `mcp/sdk` at `^0.8.1`. Runtime dependencies are `evolvephp/contracts`, `evolvephp/core`, `evolvephp/secret-contracts`, `mcp/sdk`, `psr/http-client`, `psr/http-factory`, `psr/http-message` and `psr/http-server-handler`.

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

The host-supplied invoker runs on every tool call, resource read and prompt request. It receives tool and prompt arguments unchanged. Resource reads receive `['uri' => $requestedUri]`. For direct SDK or trusted STDIO use, the host is responsible for service resolution, execution scope, cleanup, authorization and policy. The restricted remote HTTP adapter uses the authenticated factory and enforces capability policy itself. Composition does not resolve or cache host services.

The SDK owns MCP schema validation, protocol dispatch, errors and result formatting. The server definition exposes the SDK server and modern stateless protocol. It performs no filesystem discovery or global registration.

## Explicit component contributions

A host may bind an enabled module or installed plugin to its exact declaration object from Core's resolved component graph using `McpComponentContribution`. Pass those bindings, the graph, and optional host definitions to `McpContributionServerComposer::compose()`. The coordinator runs callbacks in Core's dependency-first order, with declaration order retained within each callback. Components without bindings contribute nothing.

Callbacks receive only `McpCapabilityRegistrar`, which registers immutable tool, resource, and prompt definitions with host-owned service IDs. The registrar expires after the callback. Duplicate identities are rejected per capability kind, including collisions with host definitions. The catalog and SDK server are returned only after all callbacks succeed. A failure identifies the component through `McpContributionFailed::component()` and retains the original throwable as its previous exception; callback side effects outside MCP are not rolled back. The host remains responsible for enablement, service resolution, authorization, and execution scopes. This path performs no discovery or runtime activation.

## STDIO transport

`McpStdioCommand` is an explicit `mcp:serve` Core command. Construct it with the server definition, the same `ScopedMcpCapabilityInvoker` used by the builder, and host-opened input and protocol-output streams. Mismatched invokers are rejected. Register it in a Core `CommandRegistry` and run it through `CommandRunner` or `CliApplication`. The SDK owns and closes the protocol streams; do not use the ordinary `CommandOutput::write()` channel for diagnostics. The command writes operational failures to `writeError()` and returns a nonzero exit code if capability cleanup fails or the invoker cannot start another execution safely. Each capability invocation runs through a separate `ExecutionOrchestrator` operation, beyond the command's outer CLI scope.

The SDK STDIO transport serves handshake-era MCP. The integration test verifies `2025-06-18` initialization and tool, resource and prompt dispatch. It does not claim modern `2026-07-28` STDIO support.

## Restricted JSON-only HTTP endpoint

Remote HTTP requires a host-authenticated immutable McpPrincipal for each request. McpHttpRequestHandler accepts McpAuthorizedServerFactory, PSR-17 factories, and an authentication callback returning McpPrincipal or null. Legacy boolean authorization, including true, is denied; existing experimental HTTP integrations must migrate. The host authenticates the request and retains business authorization inside its services. MCP enforces capability-level admission.

Every definition, including a component contribution, defaults to McpCapabilityExposure::LocalOnly. The host must explicitly set RemoteApproved and supply McpCapabilityPolicy and McpSecurityAuditSink. The factory builds a fresh SDK registry per authenticated request with only visible tools, resources, and prompts. It checks permission again immediately before service invocation. Do not remotely mount the unfiltered SDK server or stateless protocol exposed by McpServerDefinition.

The following uses the implemented contracts. The application supplies $definition from McpServerBuilder, $executions, PSR-17 $responses and $streams, a real $securityAuditSink, and $hostInvoker. A no-op audit sink is unsuitable for security-sensitive operations.

```php
use Evolve\Mcp\Server\McpAuthorizedServerFactory;
use Evolve\Mcp\Server\McpCapabilityKind;
use Evolve\Mcp\Server\McpCapabilityPolicy;
use Evolve\Mcp\Server\McpPrincipal;
use Evolve\Mcp\Transport\McpHttpRequestHandler;
use Psr\Http\Message\ServerRequestInterface;

$servers = new McpAuthorizedServerFactory(
    $definition,
    $executions,
    static fn ($scope, McpPrincipal $principal, string $serviceId, array $arguments): mixed =>
        $hostInvoker($scope, $principal, $serviceId, $arguments),
    new class implements McpCapabilityPolicy {
        public function allows(McpPrincipal $principal, McpCapabilityKind $kind, string $identity): bool
        {
            return $principal->subject() === 'approved-user'
                && $kind === McpCapabilityKind::Tool
                && $identity === 'echo';
        }
    },
    $securityAuditSink,
);
$handler = new McpHttpRequestHandler(
    $servers,
    $responses,
    $streams,
    static function (ServerRequestInterface $request): ?McpPrincipal {
        $subject = $request->getAttribute('verified_subject');
        return is_string($subject) ? new McpPrincipal($subject) : null;
    },
);
```

Each audit record contains only capability kind, SHA-256 capability fingerprint, decision, and random per-request correlation. Do not record raw principals, tenants, arguments, results, authorization headers, or secrets. The secure builder uses the SDK default NullLogger because SDK debug logging can contain arguments and structured results. Policy or required audit failure denies invocation.

The endpoint retains SDK CORS, Origin and local-only Host checks. It serves modern 2026-07-28 stateless JSON responses and bodyless notifications only. Request bodies default to 4 MiB and can be configured up to 16 MiB. Serialized responses default to 1 MiB and can be configured up to 16 MiB; oversized results are withheld. This post-serialization limit does not bound peak service memory or computation time. SSE negotiation, subscriptions, legacy HTTP sessions, GET subscription, DELETE teardown, and cross-worker persistence remain unsupported.

The host owns response emission and must inspect HttpKernel outcomes and McpHttpRequestHandler::quarantineFailure() before worker reuse. An inner cleanup failure requires recycling even if the outer HttpKernel reports reusable. Full SSE, subscriptions and stateful HTTP remain deferred.

## Explicit client foundation

Construct an HTTP configuration with an HTTPS endpoint, a caller-owned PSR-18 client, PSR-17 request and stream factories, and an approval callback for the exact endpoint. The caller must configure the underlying PSR-18 client to enforce TLS validation, actual connected-IP and DNS-rebinding policy at every connection, no automatic redirects, restricted proxy routing, and concrete connection/read deadlines. The adapter rechecks the exact endpoint before each send; it cannot inspect hidden redirects or destination changes performed by the injected client.

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

The HTTP wrapper replaces the SDK's SSE-capable Accept header with `application/json`. It rejects response session headers, redirects, SSE or unexpected content types, and reads outbound request bodies and inbound response bodies within configured byte limits even when a size is unknown or false. Both default to 1 MiB and are configurable up to 16 MiB. The endpoint must be HTTPS and must contain no URL userinfo, query or fragment. HTTP supports only stateless JSON responses from the accepted restricted server. No SSE stream, session or automatic trust elevation is supported.

Client STDIO is supported on Linux PHP only for EvolvePHP 2.0 Beta. Native Windows PHP refuses STDIO at connection creation before secret resolution or child launch; HTTP client mode remains available on Windows. Native Windows PHP STDIO is deferred to the 2.1 follow-up. On Linux, use `McpClientConfiguration::stdio()` with an absolute existing executable path, explicit arguments, working directory and environment, plus an application-owned approval callback. The default protocol is `2025-06-18`. Named environment secrets can be resolved per connection. The child receives only the explicitly configured environment and resolved named secrets; an empty environment stays empty and does not inherit ambient parent credentials. Only trusted local application configuration may provide the command; remote capability declarations never become commands. The SDK owns the child process and pipes. Startup and initialization errors become categorized failures. The SDK attempts termination on close, but termination of an unresponsive child is not guaranteed.

`McpClientConnection::discover()` lists tools, resources and prompts through the SDK with page, item, duplicate and cursor-cycle limits. Results retain SDK types and listing order. Invoke only explicitly chosen capabilities through `callTool()`, `readResource()` and `getPrompt()`. A failed operation poisons that connection; close it and construct a new one. `McpClientException::category` exposes safe categories without raw protocol content or credentials. If cleanup is uncertain, `cleanupCategory` identifies it and the host must recycle the affected worker.

`ScopedMcpClient::run()` is the Core execution-scoped option. It resolves credentials, opens a fresh connection, invokes the caller callback and closes the connection for each run. It exposes `quarantineFailure()` if cleanup or execution isolation fails. The wrapper forwards no identity, tenant, cookies, authorization or other execution context by default. The application controls any deliberate, bounded propagation.
## Optional operational events

Operational events are experimental and disabled by default. A host can pass an optional `McpOperationDispatcher` to `McpAuthorizedServerFactory` or `McpClientFactory`. A standalone `ScopedMcpCapabilityInvoker` can receive the same dispatcher. The dispatcher calls a host supplied `McpOperationSink` with a normalized array and contains sink exceptions. The required `McpSecurityAuditSink` remains separate and fail closed; a diagnostic sink cannot grant a denied capability or hide a required audit or cleanup failure.

```php
use Evolve\Mcp\Observability\McpOperationDispatcher;
use Evolve\Mcp\Observability\McpOperationSink;
use Evolve\Mcp\Server\McpAuthorizedServerFactory;
use Evolve\Mcp\Client\McpClientFactory;

// The application installed evolvephp/observe and created an enabled or disabled
// OpenTelemetryComposition with its own providers and resource identity.
$instrumentation = new \Evolve\Observe\Mcp\McpOperationInstrumentation($composition);
$events = new class($instrumentation) implements McpOperationSink {
    public function __construct(private \Evolve\Observe\Mcp\McpOperationInstrumentation $instrumentation) {}
    public function record(array $fields): void
    {
        $this->instrumentation->record($fields);
    }
};
$operations = new McpOperationDispatcher($events);
$servers = new McpAuthorizedServerFactory(
    $definition,
    $executions,
    static fn ($scope, \Evolve\Mcp\Server\McpPrincipal $principal, string $serviceId, array $arguments): mixed =>
        $hostInvoker($scope, $principal, $serviceId, $arguments),
    new class implements \Evolve\Mcp\Server\McpCapabilityPolicy {
        public function allows(\Evolve\Mcp\Server\McpPrincipal $principal, \Evolve\Mcp\Server\McpCapabilityKind $kind, string $identity): bool
        {
            return $principal->subject() === 'approved-user'
                && $kind === \Evolve\Mcp\Server\McpCapabilityKind::Tool
                && $identity === 'echo';
        }
    },
    $securityAuditSink,
    $operations,
);
$clientFactory = new McpClientFactory($operations);
$connection = $clientFactory->connect($config);
try {
    $catalog = $connection->discover();
} finally {
    $connection->close();
}
```

Events contain fixed operation kind, transport and outcome values; optional SHA-256 capability identity, bounded random correlation, bounded duration, and a fixed failure category. They never contain raw principals, prompts, tool arguments or results, resource URI text, exception messages, credentials or headers. Observe's `McpOperationInstrumentation` accepts the neutral array for application-owned OpenTelemetry tracing and metrics. Its metric attributes use only the three fixed categories. Insight's `McpOperationDiagnostics` accepts the same array for a caller-owned local diagnostic sink and omits capability hashes and correlation tokens. Wire either adapter explicitly through a host `McpOperationSink`; neither optional package is a required MCP dependency. The independent installed-consumer SDK HTTP fixture is prepared for the later immutable-ref gate and has not been executed at this patch gate. Linux client STDIO interoperability remains pending external validation.
