<?php

declare(strict_types=1);

namespace Evolve\Migration;

use Throwable;

/** Immutable result of one explicit migration invocation. */
final readonly class MigrationRunReport
{
    /** @var list<AppliedMigration> */
    private array $recorded;

    /** @param list<AppliedMigration> $recorded */
    public function __construct(
        private ?MigrationPlan $plan,
        private bool $acquiredLock,
        private bool $contended,
        private bool $blocked,
        private bool $executed,
        private bool $uncertain,
        private bool $terminal,
        array $recorded,
        private ?MigrationDefinition $failedMigration,
        private ?Throwable $primaryThrowable,
        private ?Throwable $cleanupThrowable,
    ) {
        $this->recorded = $recorded;
    }

    public function plan(): ?MigrationPlan
    {
        return $this->plan;
    }
    public function acquiredLock(): bool
    {
        return $this->acquiredLock;
    }
    public function contended(): bool
    {
        return $this->contended;
    }
    public function blocked(): bool
    {
        return $this->blocked;
    }
    public function executed(): bool
    {
        return $this->executed;
    }
    public function uncertain(): bool
    {
        return $this->uncertain;
    }
    public function terminal(): bool
    {
        return $this->terminal;
    }
    /** @return list<AppliedMigration> */
    public function recorded(): array
    {
        return $this->recorded;
    }
    public function failedMigration(): ?MigrationDefinition
    {
        return $this->failedMigration;
    }
    public function primaryThrowable(): ?Throwable
    {
        return $this->primaryThrowable;
    }
    public function cleanupThrowable(): ?Throwable
    {
        return $this->cleanupThrowable;
    }

    public function successful(): bool
    {
        return !$this->contended && !$this->blocked && !$this->uncertain && $this->primaryThrowable === null && $this->cleanupThrowable === null;
    }
}
