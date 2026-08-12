<?php

declare(strict_types=1);

namespace Evolve\Core\Execution;

use LogicException;
use Throwable;

final class ExecutionOutcome
{
    private function __construct(
        private ExecutionIdentifier $identifier,
        private ExecutionKind $kind,
        private bool $primarySucceeded,
        private mixed $primaryResult,
        private ?Throwable $primaryThrowable,
        private ?Throwable $cleanupThrowable,
        private ProcessReuseDecision $reuseDecision,
    ) {
        if ($this->primarySucceeded === ($this->primaryThrowable !== null)) {
            throw new LogicException('Execution outcome must contain exactly one primary state.');
        }

        if ($this->cleanupThrowable !== null && $this->reuseDecision !== ProcessReuseDecision::QuarantineRequired) {
            throw new LogicException('Cleanup failure must require quarantine.');
        }

        if ($this->reuseDecision === ProcessReuseDecision::QuarantineRequired && $this->cleanupThrowable === null) {
            throw new LogicException('Quarantine requires an isolation failure reason.');
        }
    }

    public static function succeeded(
        ExecutionIdentifier $identifier,
        ExecutionKind $kind,
        mixed $result,
        ?Throwable $cleanupThrowable,
    ): self {
        return new self(
            $identifier,
            $kind,
            true,
            $result,
            null,
            $cleanupThrowable,
            self::reuseDecisionFor($cleanupThrowable),
        );
    }

    public static function failed(
        ExecutionIdentifier $identifier,
        ExecutionKind $kind,
        Throwable $primaryThrowable,
        ?Throwable $cleanupThrowable,
    ): self {
        return new self(
            $identifier,
            $kind,
            false,
            null,
            $primaryThrowable,
            $cleanupThrowable,
            self::reuseDecisionFor($cleanupThrowable),
        );
    }

    public function identifier(): ExecutionIdentifier
    {
        return $this->identifier;
    }

    public function kind(): ExecutionKind
    {
        return $this->kind;
    }

    public function primarySucceeded(): bool
    {
        return $this->primarySucceeded;
    }

    public function primaryFailed(): bool
    {
        return ! $this->primarySucceeded;
    }

    public function primaryResult(): mixed
    {
        if (! $this->primarySucceeded) {
            throw new LogicException('Failed execution outcomes do not contain a primary result.');
        }

        return $this->primaryResult;
    }

    public function primaryThrowable(): ?Throwable
    {
        return $this->primaryThrowable;
    }

    public function primaryThrowableOrFail(): Throwable
    {
        if ($this->primaryThrowable === null) {
            throw new LogicException('Successful execution outcomes do not contain a primary throwable.');
        }

        return $this->primaryThrowable;
    }

    public function cleanupFailed(): bool
    {
        return $this->cleanupThrowable !== null;
    }

    public function cleanupThrowable(): ?Throwable
    {
        return $this->cleanupThrowable;
    }

    public function reuseDecision(): ProcessReuseDecision
    {
        return $this->reuseDecision;
    }

    public function isReusable(): bool
    {
        return $this->reuseDecision->allowsReuse();
    }

    public function requiresQuarantine(): bool
    {
        return $this->reuseDecision->requiresQuarantine();
    }

    private static function reuseDecisionFor(?Throwable $cleanupThrowable): ProcessReuseDecision
    {
        if ($cleanupThrowable !== null) {
            return ProcessReuseDecision::QuarantineRequired;
        }

        return ProcessReuseDecision::Reusable;
    }
}
