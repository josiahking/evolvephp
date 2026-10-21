<?php

declare(strict_types=1);

namespace Evolve\Mcp\Observability;

use Throwable;

/** Host constructed and disabled until a sink is supplied. */
final readonly class McpOperationDispatcher
{
    public function __construct(private ?McpOperationSink $sink = null) {}

    public function isEnabled(): bool
    {
        return $this->sink !== null;
    }

    public function dispatch(McpOperation $operation): void
    {
        try {
            $this->sink?->record($operation->fields());
        } catch (Throwable) {
            // Diagnostics must not affect authorization, operation results or cleanup.
        }
    }
}
