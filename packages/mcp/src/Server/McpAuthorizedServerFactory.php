<?php

declare(strict_types=1);

namespace Evolve\Mcp\Server;

use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Mcp\Transport\ScopedMcpCapabilityInvoker;
use RuntimeException;
use Throwable;

/** Builds a new policy-filtered SDK registry for each authenticated HTTP request. */
final readonly class McpAuthorizedServerFactory
{
    /** @var \Closure(ExecutionScope, McpPrincipal, string, array<string, mixed>): mixed */
    private \Closure $invokeInScope;

    /** @param callable(ExecutionScope, McpPrincipal, string, array<string, mixed>): mixed $invokeInScope */
    public function __construct(
        private McpServerDefinition $source,
        private ExecutionOrchestrator $executions,
        callable $invokeInScope,
        private McpCapabilityPolicy $policy,
        private McpSecurityAuditSink $audit,
    ) {
        $this->invokeInScope = \Closure::fromCallable($invokeInScope);
        if ($source->name() === null || $source->version() === null) {
            throw new \InvalidArgumentException('MCP source definition must be built by McpServerBuilder.');
        }
    }

    public function create(McpPrincipal $principal): McpServerDefinition
    {
        $correlation = bin2hex(random_bytes(16));
        $invokeInScope = $this->invokeInScope;
        $scoped = new ScopedMcpCapabilityInvoker(
            $this->executions,
            static fn(ExecutionScope $scope, string $serviceId, array $arguments): mixed
                => $invokeInScope($scope, $principal, $serviceId, $arguments),
        );
        $guard = function (McpCapabilityKind $kind, string $identity) use ($principal, $correlation): bool {
            return $this->decision($principal, $kind, $identity, $correlation);
        };
        $builder = new McpServerBuilder($this->source->name(), $this->source->version(), $scoped, $guard);

        foreach ($this->source->tools() as $tool) {
            if ($tool->exposure === McpCapabilityExposure::RemoteApproved
                && $this->decision($principal, McpCapabilityKind::Tool, $tool->name, $correlation)) {
                $builder->addTool($tool);
            }
        }
        foreach ($this->source->resources() as $resource) {
            if ($resource->exposure === McpCapabilityExposure::RemoteApproved
                && $this->decision($principal, McpCapabilityKind::Resource, $resource->uri, $correlation)) {
                $builder->addResource($resource);
            }
        }
        foreach ($this->source->prompts() as $prompt) {
            if ($prompt->exposure === McpCapabilityExposure::RemoteApproved
                && $this->decision($principal, McpCapabilityKind::Prompt, $prompt->name, $correlation)) {
                $builder->addPrompt($prompt);
            }
        }

        return $builder->build();
    }

    private function decision(McpPrincipal $principal, McpCapabilityKind $kind, string $identity, string $correlation): bool
    {
        try {
            $allowed = $this->policy->allows($principal, $kind, $identity) === true;
            $this->audit->record($kind, hash('sha256', $kind->value . ':' . $identity), $allowed, $correlation);

            return $allowed;
        } catch (Throwable) {
            throw new RuntimeException('MCP capability policy is unavailable.');
        }
    }
}
