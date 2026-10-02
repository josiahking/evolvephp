<?php

declare(strict_types=1);

namespace Evolve\Migration;

use LogicException;

/** @experimental This API may change before stable release. */
final readonly class MigrationPlanEntry
{
    public function __construct(
        private MigrationPlanStatus $status,
        private ?MigrationDefinition $definition,
        private ?AppliedMigration $appliedMigration,
    ) {
        if ($status === MigrationPlanStatus::Pending && ($definition === null || $appliedMigration !== null)) {
            throw new LogicException('Pending entry requires only a current definition.');
        }
        if (($status === MigrationPlanStatus::Applied || $status === MigrationPlanStatus::Drifted)
            && ($definition === null || $appliedMigration === null)) {
            throw new LogicException('Applied or drifted entry requires a definition and history record.');
        }
        if ($status === MigrationPlanStatus::OrphanedApplied && ($definition !== null || $appliedMigration === null)) {
            throw new LogicException('Orphaned entry requires only an applied record.');
        }
    }

    public function status(): MigrationPlanStatus
    {
        return $this->status;
    }
    public function definition(): ?MigrationDefinition
    {
        return $this->definition;
    }
    public function appliedMigration(): ?AppliedMigration
    {
        return $this->appliedMigration;
    }
    public function fullIdentity(): string
    {
        return $this->definition?->fullIdentity() ?? $this->appliedMigration->fullIdentity();
    }
    public function order(): int
    {
        return $this->definition?->order() ?? $this->appliedMigration->order();
    }
    public function ownerKey(): string
    {
        return $this->definition?->owner()->key() ?? $this->appliedMigration->owner()->key();
    }
    public function localIdentifier(): string
    {
        return $this->definition?->identifier()->value() ?? $this->appliedMigration->identifier()->value();
    }
}
