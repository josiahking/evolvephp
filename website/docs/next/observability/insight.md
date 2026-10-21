---
title: Evolve Insight
description: Optional bounded local diagnostics for Evolve executions.
sidebar_position: 1
status: experimental
package: evolvephp/insight
---

# Evolve Insight

Insight is an optional, local Evolve-aware diagnostic surface. Applications compose a DiagnosticPipeline, capture policy, optional watchers/decorators, correlation and a store explicitly. Installing the package does not enable global capture, create a database, discover watchers or expose dashboard routes.

Core observations can be correlated with an execution identifier. Accepted diagnostic entries are bounded by policy and capacity. On completion, Insight projects a detached, immutable versioned batch instead of retaining live contexts, requests, responses or throwable objects. Rejected or redacted values are removed before persistence. Policy allows selected public/internal metadata by default, excludes secret and authentication classifications, redacts common sensitive names, and supports explicit filtering and deterministic sampling. Sampling controls volume, not confidentiality.

Supported explicit diagnostic surfaces include Core execution lifecycle, incoming HTTP middleware, and wrappers for database, PSR-16 cache, queue, object storage and outbound PSR-18 HTTP operations. SQL inspection requires separate application and capture-policy opt-ins. Bodies, credentials and full outbound URIs are excluded from first-party diagnostics. Other lifecycle and worker leak watchers remain deferred.

First-party stores retain a bounded count of completed batches. In-memory storage is for tests or short-lived local use; SQLite requires a caller-owned PDO and local path. There is no time-based retention or production telemetry export. Bounded query and native dashboard composition require an application-supplied DiagnosticAccessPolicy; Insight does not provide authentication, roles or a permissive default.

Insight has sequential reuse tests, not concurrent worker certification. Use it for local diagnostic evidence and use [Observe](observe.md) for OpenTelemetry-oriented export. See the [Insight operations guide](https://github.com/josiahking/evolvephp/blob/2.x/docs/alpha/insight.md) for current composition and safety details.

## Explicit local composition

With Insight and its dependencies available through Composer, the application can attach a bounded store-backed diagnostic sink to a Core orchestrator:

~~~php
<?php
require __DIR__ . '/vendor/autoload.php';

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Insight\DiagnosticPipeline;
use Evolve\Insight\Storage\InMemoryDiagnosticBatchStore;

$store = new InMemoryDiagnosticBatchStore(20);
$pipeline = DiagnosticPipeline::storing($store, 100, 100);
$services = new ServiceRegistry();
$services->freeze();
$orchestrator = new ExecutionOrchestrator($services, $pipeline);
~~~

This enables only the explicitly attached diagnostics sink. Configure capture policy and redaction before collecting sensitive applications' events. Insight finalizes detached batches and snapshots; optional storage is separate from business storage. Native dashboard routes are available through explicit composition, but no route, storage, or authorization policy installs itself. Local diagnostics are not a production telemetry exporter.

## MCP local diagnostics

`McpOperationDiagnostics` accepts normalized MCP operational arrays and a caller-owned execution identifier and `DiagnosticEntrySink`. Its local summary records kind, transport, outcome, bounded duration and fixed failure category. It omits capability hashes and correlation tokens. The host must wire it explicitly; Insight does not require MCP and does not export production telemetry. MCP security audit is separate. After installing MCP and Insight, the host can compose the caller-owned diagnostic sink and execution identifier explicitly:

```php
$diagnostics = new \Evolve\Insight\Mcp\McpOperationDiagnostics($diagnosticEntrySink, $executionIdentifier);
$sink = new class($diagnostics) implements \Evolve\Mcp\Observability\McpOperationSink {
    public function __construct(private \Evolve\Insight\Mcp\McpOperationDiagnostics $diagnostics) {}
    public function record(array $fields): void { $this->diagnostics->record($fields); }
};
$operations = new \Evolve\Mcp\Observability\McpOperationDispatcher($sink);
$clientFactory = new \Evolve\Mcp\Client\McpClientFactory($operations);
```
