<?php

declare(strict_types=1);

namespace Evolve\Migration;

/** @experimental This API may change before stable release. */
final readonly class MigrationPlan
{
    /** @var list<MigrationPlanEntry> */
    private array $entries;

    /** @param list<MigrationPlanEntry> $entries */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    /** @return list<MigrationPlanEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<MigrationDefinition> */
    public function pending(): array
    {
        $pending = [];
        foreach ($this->entries as $entry) {
            if ($entry->status() === MigrationPlanStatus::Pending) {
                $pending[] = $entry->definition();
            }
        }
        return $pending;
    }

    public function hasDrift(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->status() === MigrationPlanStatus::Drifted) {
                return true;
            }
        }
        return false;
    }
}
