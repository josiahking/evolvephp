<?php

declare(strict_types=1);

namespace Evolve\Mcp\Server;

/** Identity is a SHA-256 capability fingerprint; correlation is random per request. */
interface McpSecurityAuditSink
{
    public function record(McpCapabilityKind $kind, string $identity, bool $allowed, string $correlation): void;
}
