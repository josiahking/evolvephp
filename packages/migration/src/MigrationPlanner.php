<?php

declare(strict_types=1);

namespace Evolve\Migration;

use InvalidArgumentException;

/** Read-only comparison of explicit definitions with durable applied history. */
final readonly class MigrationPlanner
{
    public function __construct(private MigrationRegistry $registry, private MigrationHistoryStore $history) {}

    public function plan(): MigrationPlan
    {
        $applied = [];
        foreach ($this->history->all() as $value) {
            $record = self::requireAppliedMigration($value);
            $key = $record->fullIdentity();
            if (isset($applied[$key])) {
                throw new InvalidArgumentException('Duplicate full migration identity in durable history.');
            }
            $applied[$key] = $record;
        }

        $entries = [];
        foreach ($this->registry->definitions() as $definition) {
            $key = $definition->fullIdentity();
            $record = $applied[$key] ?? null;
            unset($applied[$key]);
            if ($record === null) {
                $status = MigrationPlanStatus::Pending;
            } elseif ($record->checksum() === $definition->checksum() && $record->order() === $definition->order()) {
                $status = MigrationPlanStatus::Applied;
            } else {
                $status = MigrationPlanStatus::Drifted;
            }
            $entries[] = new MigrationPlanEntry($status, $definition, $record);
        }
        foreach ($applied as $record) {
            $entries[] = new MigrationPlanEntry(MigrationPlanStatus::OrphanedApplied, null, $record);
        }
        usort(
            $entries,
            static fn(MigrationPlanEntry $a, MigrationPlanEntry $b): int
            => $a->order() <=> $b->order()
            ?: strcmp($a->ownerKey(), $b->ownerKey())
            ?: strcmp($a->localIdentifier(), $b->localIdentifier()),
        );
        return new MigrationPlan($entries);
    }

    private static function requireAppliedMigration(mixed $value): AppliedMigration
    {
        if (!$value instanceof AppliedMigration) {
            throw new InvalidArgumentException('Migration history must contain applied migration records.');
        }

        return $value;
    }
}
