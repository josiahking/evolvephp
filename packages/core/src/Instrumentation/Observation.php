<?php

declare(strict_types=1);

namespace Evolve\Core\Instrumentation;

use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ProcessReuseDecision;

final class Observation
{
    public function __construct(
        private ObservationType $type,
        private ExecutionIdentifier $identifier,
        private ExecutionKind $kind,
        private ?ObservationOutcome $outcome = null,
        private ?string $errorType = null,
        private ?ProcessReuseDecision $reuseDecision = null,
    ) {
        if ($this->errorType === '') {
            throw new \InvalidArgumentException('Observation error type must not be empty.');
        }
    }

    public function type(): ObservationType
    {
        return $this->type;
    }

    public function identifier(): ExecutionIdentifier
    {
        return $this->identifier;
    }

    public function kind(): ExecutionKind
    {
        return $this->kind;
    }

    public function outcome(): ?ObservationOutcome
    {
        return $this->outcome;
    }

    public function errorType(): ?string
    {
        return $this->errorType;
    }

    public function reuseDecision(): ?ProcessReuseDecision
    {
        return $this->reuseDecision;
    }
}
