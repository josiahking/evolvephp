<?php

declare(strict_types=1);

namespace Evolve\Migration;

use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Lock\Contracts\LeaseDuration;
use Evolve\Lock\Contracts\LockKey;
use Evolve\Lock\Contracts\LockProvider;
use LogicException;
use Throwable;

/** One-shot forward migration execution through explicit storage and lock contracts. */
final class MigrationRunner
{
    private bool $terminal = false;

    public function __construct(
        private MigrationRegistry $registry,
        private MigrationHistoryStore $history,
        private LockProvider $locks,
        private LeaseDuration $leaseDuration,
        private ?DatabaseConnection $database = null,
    ) {}

    public function run(): MigrationRunReport
    {
        if ($this->terminal) {
            throw new LogicException('Migration runner is terminal and cannot accept more work.');
        }

        $planner = new MigrationPlanner($this->registry, $this->history);
        try {
            $plan = $planner->plan();
        } catch (Throwable $failure) {
            return $this->report(null, false, false, true, false, false, [], null, $failure, null);
        }
        if ($plan->hasDrift()) {
            return $this->report($plan, false, false, true, false, false, [], null, null, null);
        }
        if ($plan->pending() === []) {
            return $this->report($plan, false, false, false, false, false, [], null, null, null);
        }

        try {
            $lease = $this->locks->tryAcquire(new LockKey('evolvephp:migration:runtime'), $this->leaseDuration);
        } catch (Throwable $failure) {
            $this->terminal = true;
            return $this->report($plan, false, false, false, false, false, [], null, $failure, null);
        }
        if ($lease === null) {
            return $this->report($plan, false, true, false, false, false, [], null, null, null);
        }

        $blocked = false;
        $executed = false;
        $uncertain = false;
        $recorded = [];
        $failedMigration = null;
        $primary = null;
        $cleanup = null;

        try {
            try {
                $plan = $planner->plan();
                $blocked = $plan->hasDrift();
            } catch (Throwable $failure) {
                $blocked = true;
                $primary = $failure;
            }

            if (!$blocked) {
                foreach ($plan->pending() as $definition) {
                    $failedMigration = $definition;
                    try {
                        if ($definition->transactionMode() === MigrationTransactionMode::Database) {
                            if ($this->database === null) {
                                throw new LogicException('Database transaction mode requires a database connection.');
                            }
                            $database = $this->database;
                            $executed = true;
                            $database->transaction(static fn(DatabaseConnection $active): mixed => $definition->action()->invoke($active));
                        } else {
                            $executed = true;
                            $definition->action()->invoke($this->database);
                        }
                    } catch (Throwable $failure) {
                        $primary = $failure;
                        break;
                    }

                    $applied = new AppliedMigration($definition->owner(), $definition->identifier(), $definition->order(), $definition->checksum());
                    try {
                        $this->history->record($applied);
                    } catch (Throwable $failure) {
                        $primary = $failure;
                        $uncertain = true;
                        $this->terminal = true;
                        break;
                    }
                    $recorded[] = $applied;
                    $failedMigration = null;
                }
            }
        } finally {
            try {
                $lease->release();
            } catch (Throwable $failure) {
                $cleanup = $failure;
                $this->terminal = true;
            }
        }

        return $this->report($plan, true, false, $blocked, $executed, $uncertain, $recorded, $failedMigration, $primary, $cleanup);
    }

    /** @param list<AppliedMigration> $recorded */
    private function report(?MigrationPlan $plan, bool $acquiredLock, bool $contended, bool $blocked, bool $executed, bool $uncertain, array $recorded, ?MigrationDefinition $failedMigration, ?Throwable $primary, ?Throwable $cleanup): MigrationRunReport
    {
        return new MigrationRunReport($plan, $acquiredLock, $contended, $blocked, $executed, $uncertain, $this->terminal, $recorded, $failedMigration, $primary, $cleanup);
    }
}
