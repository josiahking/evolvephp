<?php

declare(strict_types=1);

namespace Evolve\Core\Execution;

final class ExecutionContext
{
    public function __construct(
        private ExecutionIdentifier $identifier,
        private ExecutionKind $kind,
    ) {}

    public function identifier(): ExecutionIdentifier
    {
        return $this->identifier;
    }

    public function kind(): ExecutionKind
    {
        return $this->kind;
    }
}
