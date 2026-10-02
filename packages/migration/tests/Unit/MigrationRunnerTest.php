<?php

declare(strict_types=1);

namespace Evolve\Migration\Tests\Unit;

use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Lock\Contracts\Lease;
use Evolve\Lock\Contracts\LeaseDuration;
use Evolve\Lock\Contracts\LockKey;
use Evolve\Lock\Contracts\LockProvider;
use Evolve\Migration\AppliedMigration;
use Evolve\Migration\MigrationAction;
use Evolve\Migration\MigrationDefinition;
use Evolve\Migration\MigrationHistoryStore;
use Evolve\Migration\MigrationIdentifier;
use Evolve\Migration\MigrationOwner;
use Evolve\Migration\MigrationRegistry;
use Evolve\Migration\MigrationRunner;
use Evolve\Migration\MigrationTransactionMode;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class MigrationRunnerTest extends TestCase
{
    public function test_success_runs_in_order_and_records_only_after_each_action(): void
    {
        $events = [];
        $records = [];
        $history = $this->history($records, $events);
        $lease = $this->lease();
        $locks = $this->locks($lease);
        $registry = new MigrationRegistry([
            $this->definition('b', 2, MigrationAction::fromCallable(static function () use (&$events): void {
                $events[] = 'action.b';
            })),
            $this->definition('a', 1, MigrationAction::fromCallable(static function () use (&$events): void {
                $events[] = 'action.a';
            })),
        ]);
        $report = (new MigrationRunner($registry, $history, $locks, new LeaseDuration(1000)))->run();
        self::assertTrue($report->successful());
        self::assertTrue($report->acquiredLock());
        self::assertSame(['action.a', 'record.a', 'action.b', 'record.b'], $events);
        self::assertCount(2, $records);
        self::assertCount(2, $report->recorded());
    }

    public function test_contention_executes_and_writes_nothing(): void
    {
        $events = [];
        $records = [];
        $locks = $this->createMock(LockProvider::class);
        $locks->expects(self::once())->method('tryAcquire')->with(self::callback(static fn(LockKey $key): bool => $key->value() === 'evolvephp:migration:runtime'), self::isInstanceOf(LeaseDuration::class))->willReturn(null);
        $runner = new MigrationRunner(new MigrationRegistry([$this->definition('one', 0, MigrationAction::fromCallable(static function () use (&$events): void {
            $events[] = 'action';
        }))]), $this->history($records, $events), $locks, new LeaseDuration(1000));
        $report = $runner->run();
        self::assertTrue($report->contended());
        self::assertFalse($report->executed());
        self::assertSame([], $events);
    }

    public function test_drift_blocks_without_action_or_history_write(): void
    {
        $events = [];
        $records = [new AppliedMigration(MigrationOwner::application(), new MigrationIdentifier('one'), 0, str_repeat('b', 64))];
        $locks = $this->createMock(LockProvider::class);
        $locks->expects(self::never())->method('tryAcquire');
        $runner = new MigrationRunner(new MigrationRegistry([$this->definition('one', 0, MigrationAction::fromCallable(static function () use (&$events): void {
            $events[] = 'action';
        }))]), $this->history($records, $events), $locks, new LeaseDuration(1000));
        $report = $runner->run();
        self::assertTrue($report->blocked());
        self::assertSame([], $events);
    }

    public function test_orphaned_history_does_not_block_unrelated_pending_migration(): void
    {
        $events = [];
        $records = [new AppliedMigration(MigrationOwner::application(), new MigrationIdentifier('orphan'), 0, str_repeat('a', 64))];
        $runner = new MigrationRunner(new MigrationRegistry([$this->definition('one', 1, MigrationAction::fromCallable(static function () use (&$events): void {
            $events[] = 'action';
        }))]), $this->history($records, $events), $this->locks($this->lease()), new LeaseDuration(1000));
        $report = $runner->run();
        self::assertTrue($report->successful());
        self::assertSame(['action', 'record.one'], $events);
    }

    public function test_action_failure_preserves_exact_throwable_and_earlier_records(): void
    {
        $failure = new RuntimeException('primary');
        $events = [];
        $records = [];
        $registry = new MigrationRegistry([
            $this->definition('first', 0, MigrationAction::fromCallable(static function () use (&$events): void {
                $events[] = 'first';
            })),
            $this->definition('failed', 1, MigrationAction::fromCallable(static fn(): never => throw $failure)),
            $this->definition('later', 2, MigrationAction::fromCallable(static function () use (&$events): void {
                $events[] = 'later';
            })),
        ]);
        $report = (new MigrationRunner($registry, $this->history($records, $events), $this->locks($this->lease()), new LeaseDuration(1000)))->run();
        self::assertSame($failure, $report->primaryThrowable());
        self::assertSame('application:failed', $report->failedMigration()?->fullIdentity());
        self::assertSame(['first', 'record.first'], $events);
        self::assertCount(1, $records);
        self::assertFalse($report->terminal());
    }

    public function test_history_failure_is_uncertain_and_runner_becomes_terminal(): void
    {
        $failure = new RuntimeException('history');
        $events = [];
        $records = [];
        $locks = $this->locks($this->lease());
        $runner = new MigrationRunner(new MigrationRegistry([$this->definition('one', 0, MigrationAction::fromCallable(static function () use (&$events): void {
            $events[] = 'action';
        }))]), $this->history($records, $events, $failure), $locks, new LeaseDuration(1000));
        $report = $runner->run();
        self::assertTrue($report->uncertain());
        self::assertTrue($report->terminal());
        self::assertSame($failure, $report->primaryThrowable());
        self::assertSame(['action', 'record.one'], $events);
        $this->expectException(LogicException::class);
        $runner->run();
    }

    public function test_database_transaction_receives_active_connection_and_none_mode_does_not_use_transaction(): void
    {
        $events = [];
        $records = [];
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->expects(self::once())->method('transaction')->willReturnCallback(static function (callable $operation) use ($connection, &$events): mixed {
            $events[] = 'transaction';
            return $operation($connection);
        });
        $registry = new MigrationRegistry([
            $this->definition('database', 0, MigrationAction::fromCallable(static function (?DatabaseConnection $active) use ($connection, &$events): void {
                TestCase::assertSame($connection, $active);
                $events[] = 'database';
            }), MigrationTransactionMode::Database),
            $this->definition('none', 1, MigrationAction::fromCallable(static function (?DatabaseConnection $active) use ($connection, &$events): void {
                TestCase::assertSame($connection, $active);
                $events[] = 'none';
            })),
        ]);
        $report = (new MigrationRunner($registry, $this->history($records, $events), $this->locks($this->lease()), new LeaseDuration(1000), $connection))->run();
        self::assertTrue($report->successful());
        self::assertSame(['transaction', 'database', 'record.database', 'none', 'record.none'], $events);
    }

    public function test_database_mode_without_connection_fails_before_action(): void
    {
        $events = [];
        $records = [];
        $registry = new MigrationRegistry([$this->definition('one', 0, MigrationAction::fromCallable(static function () use (&$events): void {
            $events[] = 'action';
        }), MigrationTransactionMode::Database)]);
        $report = (new MigrationRunner($registry, $this->history($records, $events), $this->locks($this->lease()), new LeaseDuration(1000)))->run();
        self::assertInstanceOf(LogicException::class, $report->primaryThrowable());
        self::assertSame([], $events);
    }

    public function test_cleanup_failure_is_separate_and_terminal(): void
    {
        $cleanup = new RuntimeException('cleanup');
        $events = [];
        $records = [];
        $lease = $this->createMock(Lease::class);
        $lease->expects(self::once())->method('release')->willThrowException($cleanup);
        $runner = new MigrationRunner(new MigrationRegistry([$this->definition('one', 0, MigrationAction::fromCallable(static fn(): null => null))]), $this->history($records, $events), $this->locks($lease), new LeaseDuration(1000));
        $report = $runner->run();
        self::assertSame($cleanup, $report->cleanupThrowable());
        self::assertTrue($report->terminal());
        self::assertNull($report->primaryThrowable());
        $this->expectException(LogicException::class);
        $runner->run();
    }

    public function test_fully_applied_plan_does_not_acquire_lock_or_repeat_action(): void
    {
        $events = [];
        $records = [new AppliedMigration(MigrationOwner::application(), new MigrationIdentifier('one'), 0, str_repeat('a', 64))];
        $locks = $this->createMock(LockProvider::class);
        $locks->expects(self::never())->method('tryAcquire');
        $registry = new MigrationRegistry([$this->definition('one', 0, MigrationAction::fromCallable(static function () use (&$events): void {
            $events[] = 'action';
        }))]);
        $report = (new MigrationRunner($registry, $this->history($records, $events), $locks, new LeaseDuration(1000)))->run();
        self::assertTrue($report->successful());
        self::assertFalse($report->acquiredLock());
        self::assertSame([], $events);
    }

    public function test_plan_refresh_under_lock_blocks_new_drift_and_releases_lease(): void
    {
        $events = [];
        $records = [];
        $lease = $this->lease();
        $locks = $this->createMock(LockProvider::class);
        $locks->expects(self::once())->method('tryAcquire')->willReturnCallback(static function () use (&$records, $lease): Lease {
            $records[] = new AppliedMigration(MigrationOwner::application(), new MigrationIdentifier('one'), 0, str_repeat('b', 64));
            return $lease;
        });
        $registry = new MigrationRegistry([$this->definition('one', 0, MigrationAction::fromCallable(static function () use (&$events): void {
            $events[] = 'action';
        }))]);
        $report = (new MigrationRunner($registry, $this->history($records, $events), $locks, new LeaseDuration(1000)))->run();
        self::assertTrue($report->blocked());
        self::assertTrue($report->acquiredLock());
        self::assertFalse($report->executed());
        self::assertSame([], $events);
    }

    public function test_duplicate_durable_history_blocks_before_lock(): void
    {
        $events = [];
        $record = new AppliedMigration(MigrationOwner::application(), new MigrationIdentifier('one'), 0, str_repeat('a', 64));
        $records = [$record, $record];
        $locks = $this->createMock(LockProvider::class);
        $locks->expects(self::never())->method('tryAcquire');
        $registry = new MigrationRegistry([$this->definition('two', 1, MigrationAction::fromCallable(static function () use (&$events): void {
            $events[] = 'action';
        }))]);
        $report = (new MigrationRunner($registry, $this->history($records, $events), $locks, new LeaseDuration(1000)))->run();
        self::assertTrue($report->blocked());
        self::assertFalse($report->executed());
        self::assertSame([], $events);
    }

    public function test_cleanup_failure_preserves_primary_action_failure_identity(): void
    {
        $primary = new RuntimeException('action');
        $cleanup = new RuntimeException('release');
        $events = [];
        $records = [];
        $lease = $this->createMock(Lease::class);
        $lease->expects(self::once())->method('release')->willThrowException($cleanup);
        $registry = new MigrationRegistry([$this->definition('one', 0, MigrationAction::fromCallable(static fn(): never => throw $primary))]);
        $runner = new MigrationRunner($registry, $this->history($records, $events), $this->locks($lease), new LeaseDuration(1000));
        $report = $runner->run();
        self::assertSame($primary, $report->primaryThrowable());
        self::assertSame($cleanup, $report->cleanupThrowable());
        self::assertTrue($report->terminal());
        self::assertSame([], $records);
    }

    private function definition(string $id, int $order, MigrationAction $action, MigrationTransactionMode $mode = MigrationTransactionMode::None): MigrationDefinition
    {
        return new MigrationDefinition(MigrationOwner::application(), new MigrationIdentifier($id), $order, str_repeat('a', 64), $action, $mode);
    }

    /**
     * @param list<AppliedMigration> $records
     * @param list<string> $events
     */
    private function history(array &$records, array &$events, ?Throwable $failure = null): MigrationHistoryStore
    {
        return new class ($records, $events, $failure) implements MigrationHistoryStore {
            /**
             * @param list<AppliedMigration> $records
             * @param list<string> $events
             */
            public function __construct(public array &$records, public array &$events, private ?Throwable $failure) {}
            public function all(): iterable
            {
                return $this->records;
            }
            public function record(AppliedMigration $migration): void
            {
                $this->events[] = 'record.' . $migration->identifier()->value();
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                $this->records[] = $migration;
            }
        };
    }

    private function locks(?Lease $lease): LockProvider
    {
        $locks = $this->createMock(LockProvider::class);
        $locks->expects(self::once())->method('tryAcquire')->willReturn($lease);
        return $locks;
    }

    private function lease(): Lease
    {
        $lease = $this->createMock(Lease::class);
        $lease->expects(self::once())->method('release');
        return $lease;
    }
}
