<?php

declare(strict_types=1);

namespace Evolve\Migration;

/** Durable storage boundary; implementations own their schema and transaction choices. */
interface MigrationHistoryStore
{
    /** @return iterable<AppliedMigration> */
    public function all(): iterable;
    public function record(AppliedMigration $migration): void;
}
