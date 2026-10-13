<?php

declare(strict_types=1);

namespace Evolve\Mcp\Server;

use InvalidArgumentException;

/** Host-verified identity for one remote MCP request. Never serialize this value into MCP output. */
final readonly class McpPrincipal
{
    public function __construct(private string $subject)
    {
        if ($subject === '' || strlen($subject) > 256 || preg_match('/[\x00-\x1f\x7f]/', $subject) === 1) {
            throw new InvalidArgumentException('Invalid MCP principal.');
        }
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function __debugInfo(): array
    {
        return ['subject' => '[REDACTED]'];
    }
}
