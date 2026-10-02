<?php

declare(strict_types=1);

namespace Evolve\Migration\Tests\Unit\Console;

use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Lock\Contracts\Lease;
use Evolve\Lock\Contracts\LeaseDuration;
use Evolve\Lock\Contracts\LockProvider;
use Evolve\Migration\AppliedMigration;
use Evolve\Migration\Console\MigrateCommand;
use Evolve\Migration\MigrationAction;
use Evolve\Migration\MigrationDefinition;
use Evolve\Migration\MigrationHistoryStore;
use Evolve\Migration\MigrationIdentifier;
use Evolve\Migration\MigrationOwner;
use Evolve\Migration\MigrationPlanner;
use Evolve\Migration\MigrationRegistry;
use Evolve\Migration\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class MigrateCommandTest extends TestCase
{
    public function test_migrate_applies_pending_migration_without_nested_command_execution(): void
    {
        $calls = 0;
        $records = [];
        $registry = $this->registry(static function () use (&$calls): void {
            ++$calls;
        });
        $history = $this->history($records);
        $lease = $this->createMock(Lease::class);
        $lease->expects(self::once())->method('release');
        $locks = $this->createMock(LockProvider::class);
        $locks->expects(self::once())->method('tryAcquire')->willReturn($lease);
        $command = new MigrateCommand(new MigrationPlanner($registry, $history), new MigrationRunner($registry, $history, $locks, new LeaseDuration(1000)));
        $output = $this->captureOutput();
        self::assertSame('migrate', $command->name());
        self::assertSame(0, $command->execute(new CommandInput(), $output)->exitCode());
        self::assertSame(1, $calls);
        self::assertCount(1, $records);
        self::assertSame(['Migrations completed: 1 recorded.'], $output->messages);
    }

    public function test_dry_run_and_status_are_read_only_and_lock_free(): void
    {
        $calls = 0;
        $records = [];
        $registry = $this->registry(static function () use (&$calls): void {
            ++$calls;
        });
        $history = $this->history($records);
        $locks = $this->createMock(LockProvider::class);
        $locks->expects(self::never())->method('tryAcquire');
        $command = new MigrateCommand(new MigrationPlanner($registry, $history), new MigrationRunner($registry, $history, $locks, new LeaseDuration(1000)));
        foreach (['--dry-run', '--status'] as $flag) {
            $output = $this->captureOutput();
            self::assertSame(0, $command->execute(new CommandInput([$flag]), $output)->exitCode());
            self::assertCount(1, $output->messages);
            self::assertStringContainsString('pending=1', $output->messages[0]);
            self::assertSame([], $output->errors);
        }
        self::assertSame(0, $calls);
        self::assertSame([], $records);
    }

    public function test_invalid_tokens_have_bounded_error_and_no_mutation(): void
    {
        $records = [];
        $registry = $this->registry(static fn(): null => null);
        $history = $this->history($records);
        $locks = $this->createMock(LockProvider::class);
        $locks->expects(self::never())->method('tryAcquire');
        $command = new MigrateCommand(new MigrationPlanner($registry, $history), new MigrationRunner($registry, $history, $locks, new LeaseDuration(1000)));
        foreach ([['--unknown'], ['--dry-run', '--status'], ['--status', '--status'], ['extra']] as $tokens) {
            $output = $this->captureOutput();
            self::assertNotSame(0, $command->execute(new CommandInput($tokens), $output)->exitCode());
            self::assertCount(1, $output->errors);
            self::assertSame([], $output->messages);
        }
        self::assertSame([], $records);
    }

    /** @param callable(): mixed $action */
    private function registry(callable $action): MigrationRegistry
    {
        return new MigrationRegistry([
            new MigrationDefinition(MigrationOwner::application(), new MigrationIdentifier('one'), 0, str_repeat('a', 64), MigrationAction::fromCallable($action)),
        ]);
    }

    /** @param list<AppliedMigration> $records */
    private function history(array &$records): MigrationHistoryStore
    {
        return new class ($records) implements MigrationHistoryStore {
            /** @param list<AppliedMigration> $records */
            public function __construct(public array &$records) {}
            public function all(): iterable
            {
                return $this->records;
            }
            public function record(AppliedMigration $migration): void
            {
                $this->records[] = $migration;
            }
        };
    }

    /** @return CommandOutput&object{messages: list<string>, errors: list<string>} */
    private function captureOutput(): CommandOutput
    {
        return new class implements CommandOutput {
            /** @var list<string> */
            public array $messages = [];
            /** @var list<string> */
            public array $errors = [];
            public function write(string $message): void
            {
                $this->messages[] = $message;
            }
            public function writeError(string $message): void
            {
                $this->errors[] = $message;
            }
        };
    }
}
