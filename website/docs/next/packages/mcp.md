---
title: MCP
description: Experimental explicit MCP server, component contributions, transports, and client boundaries.
sidebar_position: 2
status: experimental
package: evolvephp/mcp
---

# MCP

## What it is

`evolvephp/mcp` is an optional, experimental MCP server and client foundation. EvolvePHP 2 is pre-release; the package is not independently published. The host chooses which capabilities to expose, which components are enabled, and how calls are authorized and executed.

## Explicit server composition

`McpServerBuilder` accepts immutable `McpToolDefinition`, `McpResourceDefinition`, and `McpPromptDefinition` objects. Each maps a public MCP identity to a host-owned service ID. A host-provided `McpCapabilityInvoker` resolves and invokes that ID per call. The builder freezes after its first build and rejects duplicate identities. Its SDK server and stateless protocol are available from the built definition.

## Component contributions

The host can bind enabled module or plugin declarations from Core's resolved component graph to `McpComponentContribution` callbacks. The callback receives a short-lived `McpCapabilityRegistrar`, which registers tool, resource, and prompt definitions. There is no filesystem discovery or automatic component activation.

This example follows the current MCP integration test. It uses the exact declaration object passed to the Core graph resolver and a host-owned invoker. Run it in a source workspace with the package dependencies available through Composer.

~~~php
<?php
require __DIR__ . '/vendor/autoload.php';

use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Core\Component\ComponentGraphResolver;
use Evolve\Mcp\Contribution\McpCapabilityRegistrar;
use Evolve\Mcp\Contribution\McpComponentContribution;
use Evolve\Mcp\Contribution\McpContributionServerComposer;
use Evolve\Mcp\Server\McpCapabilityInvoker;
use Evolve\Mcp\Server\McpToolDefinition;

$declaration = new ComponentGraphDeclaration(new ComponentIdentifier('plugin/one'));
$graph = (new ComponentGraphResolver())->resolve([$declaration]);
$invoker = new class implements McpCapabilityInvoker {
    public function invoke(string $serviceId, array $arguments): mixed
    {
        // The host applies authorization and resolves an execution-scoped service here.
        return ['service' => $serviceId, 'arguments' => $arguments];
    }
};

$contribution = new McpComponentContribution(
    $declaration,
    static function (McpCapabilityRegistrar $registrar): void {
        $registrar->registerTool(new McpToolDefinition(
            'component',
            'host.tool',
            ['type' => 'object'],
        ));
    },
);

$server = (new McpContributionServerComposer('example', '1.0', $invoker))
    ->compose($graph, [$contribution]);
~~~

The example deliberately exposes only one tool. The same registrar can register `McpResourceDefinition` and `McpPromptDefinition`; the host may also pass its own definitions to `compose()`. Core resolves the graph first. Contributions run in Core's dependency-first order, preserving declaration order within a callback. Components without explicit bindings contribute nothing.

Duplicate tool names, resource URIs, or prompt names are rejected within their capability kind, including collisions with host definitions. The composer publishes a server only after all callbacks succeed. A callback failure is wrapped as `McpContributionFailed` with its component identity and prior throwable; side effects outside the MCP catalog are not rolled back. A registrar cannot be retained for later registration.

The host owns component enablement, service resolution, per-call authorization, execution scopes, and cleanup. Registration never invokes the target service or grants permission to call it.

## Transport boundaries

The opt-in `McpStdioCommand` serves handshake-era MCP through host-opened protocol streams and a `ScopedMcpCapabilityInvoker`. Register it with Core CLI composition. The SDK owns protocol streams; ordinary command output is not the protocol channel. Each capability invocation uses a separate Core execution. Cleanup or isolation failure can require worker quarantine. Current STDIO server integration verifies the `2025-06-18` protocol, not the newer HTTP protocol.

`McpHttpRequestHandler` is an explicitly mounted, authorization-gated PSR-15 endpoint. It supports restricted stateless JSON responses and bodyless notifications under the modern `2026-07-28` protocol. It rejects SSE, subscriptions, session lifecycle behavior, and non-JSON response modes. The host owns response emission and worker recycling after inner capability quarantine. SDK Origin and Host checks supplement host authorization; the default Host allowlist is local only.

## Client foundation

The explicit client supports policy-checked HTTPS JSON mode with caller-owned PSR-18 and PSR-17 implementations, endpoint approval, TLS, destination controls, redirect policy, and real network deadlines. `discover()` lists capabilities without invoking them; `callTool()`, `readResource()`, and `getPrompt()` invoke only selected capabilities. Close a failed connection and create a new one. Named secrets are resolved per connection.

Client STDIO is currently supported only on Linux PHP. Native Windows PHP rejects STDIO configuration before launching a child; HTTP client mode remains available on Windows. On supported Linux PHP, the host supplies an absolute executable, arguments, environment, working directory, and approval callback. No ambient parent credentials are inherited by an empty configured environment. This is an implementation limitation, not a requirement to use Linux for the documentation site.

`ScopedMcpClient` can open, use, and close a fresh connection within one Core execution. It does not forward user, tenant, authorization, or cookie context automatically. The host owns any deliberate propagation and quarantine response.

## Current limitations

No automatic MCP discovery, route installation, authorization policy, host service wiring, or component activation is supplied. The HTTP endpoint has no SSE, persistent session, subscription, or cross-worker state. The client does not grant remote declarations authority to launch local commands. These boundaries must be composed and reviewed by the application.

See the [MCP package reference](https://github.com/josiahking/evolvephp/blob/2.x/packages/mcp/README.md), [package overview](overview.md), and [execution model](../concepts/execution-model.md).

## Optional operational events

Operational events are experimental and disabled by default. Pass an explicit `McpOperationDispatcher` to `McpAuthorizedServerFactory` or `McpClientFactory` to record bounded registration, discovery, invocation, connection and HTTP policy transport outcomes. `McpSecurityAuditSink` remains the required fail-closed security record. A throwing optional diagnostic sink cannot change a denial or cleanup result.

The `McpOperationSink::record(array $fields)` callback receives fixed kind, transport and outcome categories, optional SHA-256 capability hash, bounded random correlation and duration, and a fixed failure category. It receives no raw principal, prompt, argument, result, resource URI, header, secret or exception message. A host can pass the neutral array to Observe's `McpOperationInstrumentation` or Insight's `McpOperationDiagnostics`; neither adapter imports MCP production classes. Observe provides application-owned OpenTelemetry tracing and metrics; Insight provides local diagnostic summaries. The package reference shows concrete `McpOperationSink`, server-factory and client-factory composition. The SDK HTTP installed-consumer fixture is prepared for the later immutable-ref gate; Linux PHP client STDIO interop remains pending validation.
