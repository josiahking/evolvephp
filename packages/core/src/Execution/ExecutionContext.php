<?php

declare(strict_types=1);

namespace Evolve\Core\Execution;

final class ExecutionContext
{
    private ExecutionContextValues $values;

    public function __construct(
        private ExecutionIdentifier $identifier,
        private ExecutionKind $kind,
        ?ExecutionContextValues $values = null,
    ) {
        $this->values = $values ?? new ExecutionContextValues();
    }

    public function identifier(): ExecutionIdentifier
    {
        return $this->identifier;
    }

    public function kind(): ExecutionKind
    {
        return $this->kind;
    }

    public function values(): ExecutionContextValues
    {
        return $this->values;
    }

    public function locale(): ?string
    {
        return $this->values()->locale();
    }

    public function timezone(): ?string
    {
        return $this->values()->timezone();
    }
}
