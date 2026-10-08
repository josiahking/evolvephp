<?php

declare(strict_types=1);

namespace Evolve\Mcp\Server;

/** The host resolves and invokes a service within the current execution scope. */
interface McpCapabilityInvoker
{
    /** @param array<string, mixed> $arguments */
    public function invoke(string $serviceId, array $arguments): mixed;
}
