<?php

declare(strict_types=1);

namespace Evolve\Mcp\Server;

/** Host decision enforced by MCP for both listing and invocation. */
interface McpCapabilityPolicy
{
    public function allows(McpPrincipal $principal, McpCapabilityKind $kind, string $identity): bool;
}
