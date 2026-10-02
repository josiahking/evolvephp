<?php

declare(strict_types=1);

namespace Evolve\Migration\Tests\Unit;

use Evolve\Migration\AppliedMigration;
use Evolve\Migration\MigrationAction;
use Evolve\Migration\MigrationDefinition;
use Evolve\Migration\MigrationHistoryStore;
use Evolve\Migration\MigrationIdentifier;
use Evolve\Migration\MigrationOwner;
use Evolve\Migration\MigrationPlanEntry;
use Evolve\Migration\MigrationPlanner;
use Evolve\Migration\MigrationPlanStatus;
use Evolve\Migration\MigrationRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MigrationPlannerTest extends TestCase
{
    public function test_classifies_pending_applied_checksum_and_order_drift_and_orphaned_history(): void
    {
        $owner = MigrationOwner::application();
        $definitions = [
            $this->definition('pending', 0),
            $this->definition('applied', 1),
            $this->definition('checksum', 2),
            $this->definition('order', 3),
        ];
        $history = $this->history([
            new AppliedMigration($owner, new MigrationIdentifier('applied'), 1, str_repeat('a', 64)),
            new AppliedMigration($owner, new MigrationIdentifier('checksum'), 2, str_repeat('b', 64)),
            new AppliedMigration($owner, new MigrationIdentifier('order'), 9, str_repeat('a', 64)),
            new AppliedMigration($owner, new MigrationIdentifier('orphan'), 4, str_repeat('a', 64)),
        ]);
        $plan = (new MigrationPlanner(new MigrationRegistry($definitions), $history))->plan();
        $statuses = array_map(static fn(MigrationPlanEntry $entry): MigrationPlanStatus => $entry->status(), $plan->entries());
        self::assertSame([MigrationPlanStatus::Pending, MigrationPlanStatus::Applied, MigrationPlanStatus::Drifted, MigrationPlanStatus::Drifted, MigrationPlanStatus::OrphanedApplied], $statuses);
        self::assertTrue($plan->hasDrift());
        self::assertCount(1, $plan->pending());
    }

    public function test_history_input_order_does_not_change_plan_order(): void
    {
        $owner = MigrationOwner::application();
        $a = new AppliedMigration($owner, new MigrationIdentifier('z'), 3, str_repeat('a', 64));
        $b = new AppliedMigration($owner, new MigrationIdentifier('a'), 2, str_repeat('a', 64));
        $registry = new MigrationRegistry([]);
        $first = (new MigrationPlanner($registry, $this->history([$a, $b])))->plan();
        $second = (new MigrationPlanner($registry, $this->history([$b, $a])))->plan();
        self::assertSame(array_map(static fn(MigrationPlanEntry $entry): string => $entry->fullIdentity(), $first->entries()), array_map(static fn(MigrationPlanEntry $entry): string => $entry->fullIdentity(), $second->entries()));
        self::assertFalse($first->hasDrift());
    }

    public function test_duplicate_full_identity_in_history_is_rejected(): void
    {
        $record = new AppliedMigration(MigrationOwner::application(), new MigrationIdentifier('same'), 0, str_repeat('a', 64));
        $this->expectException(InvalidArgumentException::class);
        (new MigrationPlanner(new MigrationRegistry([]), $this->history([$record, $record])))->plan();
    }

    private function definition(string $id, int $order): MigrationDefinition
    {
        return new MigrationDefinition(MigrationOwner::application(), new MigrationIdentifier($id), $order, str_repeat('a', 64), MigrationAction::fromCallable(static fn(): null => null));
    }

    /** @param list<AppliedMigration> $records */
    private function history(array $records): MigrationHistoryStore
    {
        return new class ($records) implements MigrationHistoryStore {
            /** @param list<AppliedMigration> $records */
            public function __construct(private array $records) {}
            public function all(): iterable
            {
                return $this->records;
            }
            public function record(AppliedMigration $migration): void
            {
                throw new \LogicException('Read-only planning wrote history.');
            }
        };
    }
}
