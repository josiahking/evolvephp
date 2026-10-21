<?php

declare(strict_types=1);

namespace Evolve\Mcp\Observability;

interface McpOperationSink
{
    /** @param array<string, string|int> $fields */
    public function record(array $fields): void;
}
