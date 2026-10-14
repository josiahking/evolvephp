# EvolvePHP AI

EvolvePHP 2 is pre-release; this package is not yet independently published. Its canonical source is the EvolvePHP monorepo at https://github.com/josiahking/evolvephp. License: BSD-3-Clause; see `LICENSE.md`. Runtime dependencies: PHP only.

`evolvephp/ai` is an optional, experimental pre-release package for PHP `^8.4`. It defines synchronous, provider-neutral model invocation data and contracts. Optional experimental provider-neutral synchronous model invocation contracts and caller-controlled tool handoff. It ships no provider adapter, vendor SDK, network client, retry loop, global provider discovery, or automatic tool execution. The package can be installed independently of EvolvePHP Core and MCP.

Applications own providers, credentials, transport deadlines, cancellation checks, sensitive-data policy, and every tool authorization decision. A model's `ToolCall` is data requesting a tool; it grants no permission to execute it. If an application uses MCP, its host explicitly selects and authorizes an MCP capability before invoking it. AI and MCP have no direct runtime dependency.

## Application-owned provider

The following local provider has no SDK or network call. The integration test uses this same contract.

```php
use Evolve\Ai\InvocationOptions;
use Evolve\Ai\ModelMessage;
use Evolve\Ai\ModelProvider;
use Evolve\Ai\ModelRequest;
use Evolve\Ai\ModelResponse;
use Evolve\Ai\ModelRole;
use Evolve\Ai\TextPart;
use Evolve\Ai\Usage;

$provider = new class implements ModelProvider {
    public function invoke(ModelRequest $request, InvocationOptions $options): ModelResponse
    {
        if ($options->cancellationSignal?->isCancellationRequested()) {
            throw new \Evolve\Ai\ModelInvocationException(\Evolve\Ai\ModelFailureCategory::Cancelled);
        }

        return new ModelResponse([new TextPart('Local answer')], new Usage(2, 3));
    }
};

$result = $provider->invoke(
    new ModelRequest([new ModelMessage(ModelRole::User, [new TextPart('Hello')])]),
    new InvocationOptions(3.0),
);
```

`ModelResponse::usage` may be null when a provider cannot report portable token counts. `InvocationOptions::timeoutSeconds` is a finite positive budget for the adapter to enforce through its transport. PHP cannot forcibly interrupt an arbitrary blocking vendor SDK call. `CancellationSignal` is cooperative; an adapter must check it before and during work where possible.

## Tool handoff

Declare tools with `ToolDefinition` and an object input schema. An assistant response may contain `ToolCall` values with stable IDs. The application checks the requested tool name and arguments against its own policy, executes only an authorized operation, and supplies a `ToolResultPart` in a subsequent `ModelRequest`. The request rejects unmatched or duplicate result IDs. No call is dispatched by this package.

Tool arguments and input schemas are validated, bounded snapshots. Their public PHP arrays are for inspection. Provider adapters should use `ToolCall::$argumentsJson` and `ToolDefinition::$inputSchemaJson` directly. These JSON strings preserve object semantics for empty arguments (`{}`) and schema `properties` (`{}`); encoding the PHP arrays directly would turn empty arrays into `[]`. Zero-argument tools are supported with an empty arguments array.

`ModelInvocationException` exposes a portable `ModelFailureCategory` and a fixed message without prompts, tool arguments, credentials, or model output. Adapters should avoid chaining sensitive vendor exceptions into application-visible diagnostics.
