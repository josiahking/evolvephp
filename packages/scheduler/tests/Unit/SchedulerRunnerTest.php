<?php

declare(strict_types=1);

namespace Evolve\Scheduler\Tests\Unit;

use DateTimeImmutable;
use Evolve\Core\Console\Command;
use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Core\Console\CommandRegistry;
use Evolve\Core\Console\CommandResult;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Core\Execution\ProcessReuseDecision;
use Evolve\Lock\Contracts\Exception\LockException;
use Evolve\Lock\Contracts\Lease;
use Evolve\Lock\Contracts\LeaseDuration;
use Evolve\Lock\Contracts\LockKey;
use Evolve\Lock\Contracts\LockProvider;
use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueuePublisher;
use Evolve\Scheduler\CatchUpPolicy;
use Evolve\Scheduler\CronSchedule;
use Evolve\Scheduler\OverlapProtection;
use Evolve\Scheduler\ScheduledAction;
use Evolve\Scheduler\ScheduleDefinition;
use Evolve\Scheduler\ScheduleRegistry;
use Evolve\Scheduler\SchedulerRunner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

final class SchedulerRunnerTest extends TestCase
{
    public function test_skip_only_current_and_run_once_reports_latest_missed_occurrence(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:05:30Z');
        $calls = 0;
        $action = ScheduledAction::callback(static function () use (&$calls): int {
            return ++$calls;
        });
        $runner = $this->runner($now, [
            $this->definition('task.skip', '0 * * * *', $action),
            $this->definition('task.once', '* * * * *', $action, CatchUpPolicy::RunOnce),
        ]);
        $report = $runner->runTick(new DateTimeImmutable('2026-01-01T00:00:00Z'));
        self::assertCount(1, $report->runs());
        self::assertSame('task.once', $report->runs()[0]->scheduleIdentifier());
        self::assertSame('00:05', $report->runs()[0]->scheduledOccurrence()->format('H:i'));
        self::assertSame(1, $calls);
        self::assertSame(1, $report->runs()[0]->executionOutcome()->primaryResult()->result());
        self::assertSame(ProcessReuseDecision::Reusable, $report->reuseDecision());
    }

    public function test_startup_does_not_replay_and_repeated_runs_have_fresh_scope(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:05:30Z');
        $seen = [];
        $action = ScheduledAction::callback(static function (ExecutionContext $context, ExecutionScope $scope) use (&$seen): string {
            $seen[] = [$context->identifier()->value(), $scope, $context->kind(), $context->locale(), $context->timezone()];
            return 'done';
        });
        $runner = $this->runner($now, [$this->definition('task.once', '* * * * *', $action, CatchUpPolicy::RunOnce, timezone: 'Europe/Paris', locale: 'fr-FR')]);
        $original = date_default_timezone_get();
        $first = $runner->runTick();
        $second = $runner->runTick();
        self::assertCount(1, $first->runs());
        self::assertCount(1, $second->runs());
        self::assertNotSame($seen[0][0], $seen[1][0]);
        self::assertNotSame($seen[0][1], $seen[1][1]);
        self::assertSame(ExecutionKind::ScheduledJob, $seen[0][2]);
        self::assertSame(['fr-FR', 'Europe/Paris'], array_slice($seen[0], 3));
        self::assertSame($original, date_default_timezone_get());
    }

    public function test_run_once_uses_latest_missed_occurrence_when_current_minute_is_not_due(): void
    {
        $runner = $this->runner(new DateTimeImmutable('2026-01-01T00:05:30Z'), [
            $this->definition('task.one', '*/2 * * * *', ScheduledAction::callback(static fn(): string => 'once'), CatchUpPolicy::RunOnce),
        ]);
        $report = $runner->runTick(new DateTimeImmutable('2026-01-01T00:00:00Z'));
        self::assertCount(1, $report->runs());
        self::assertSame('00:04', $report->runs()[0]->scheduledOccurrence()->format('H:i'));
        self::assertCount(0, $runner->runTick(new DateTimeImmutable('2026-01-01T00:04:00Z'))->runs());
    }

    public function test_null_checkpoint_does_not_replay_missed_occurrences(): void
    {
        $runner = $this->runner(new DateTimeImmutable('2026-01-01T00:05:30Z'), [
            $this->definition('task.one', '*/2 * * * *', ScheduledAction::callback(static fn(): never => throw new RuntimeException('historical replay')), CatchUpPolicy::RunOnce),
        ]);
        self::assertCount(0, $runner->runTick()->runs());
    }

    public function test_scheduled_command_executes_once_inside_scheduled_job(): void
    {
        $calls = 0;
        $result = new CommandResult(7);
        $command = new class ($result, $calls) implements Command {
            public function __construct(private CommandResult $result, public int &$calls) {}
            public function name(): string
            {
                return 'task:run';
            }
            public function description(): string
            {
                return 'task';
            }
            public function execute(CommandInput $input, CommandOutput $output): CommandResult
            {
                ++$this->calls;
                return $this->result;
            }
        };
        $action = ScheduledAction::command(new CommandRegistry([$command]), 'task:run', new CommandInput(), $this->createStub(CommandOutput::class));
        $runner = $this->runner(new DateTimeImmutable('2026-01-01T00:00:00Z'), [$this->definition('task.command', '* * * * *', $action)]);
        $outcome = $runner->runTick()->runs()[0]->executionOutcome();
        self::assertSame(1, $calls);
        self::assertSame(ExecutionKind::ScheduledJob, $outcome->kind());
        self::assertSame($result, $outcome->primaryResult()->result());
    }

    public function test_scheduled_queue_job_only_publishes_one_message(): void
    {
        $queue = new QueueName('jobs');
        $message = new MessageEnvelope('opaque', ['trace' => 'opaque']);
        $publisher = $this->createMock(QueuePublisher::class);
        $publisher->expects(self::once())->method('publish')->with($queue, $message);
        $action = ScheduledAction::queueJob($publisher, $queue, $message);
        $runner = $this->runner(new DateTimeImmutable('2026-01-01T00:00:00Z'), [$this->definition('task.publish', '* * * * *', $action)]);
        $outcome = $runner->runTick()->runs()[0]->executionOutcome();
        self::assertSame(ExecutionKind::ScheduledJob, $outcome->kind());
        self::assertNull($outcome->primaryResult()->result());
    }

    public function test_clean_primary_failure_preserves_throwable_and_runs_later_task(): void
    {
        $failure = new RuntimeException('primary');
        $runner = $this->runner(new DateTimeImmutable('2026-01-01T00:00:00Z'), [
            $this->definition('task.a', '* * * * *', ScheduledAction::callback(static fn(): never => throw $failure)),
            $this->definition('task.b', '* * * * *', ScheduledAction::callback(static fn(): string => 'later')),
        ]);
        $report = $runner->runTick();
        self::assertCount(2, $report->runs());
        self::assertSame($failure, $report->runs()[0]->executionOutcome()->primaryThrowable());
        self::assertSame('later', $report->runs()[1]->executionOutcome()->primaryResult()->result());
        self::assertSame(ProcessReuseDecision::Reusable, $report->reuseDecision());
    }

    public function test_previous_check_in_future_is_rejected_before_execution(): void
    {
        $calls = 0;
        $runner = $this->runner(new DateTimeImmutable('2026-01-01T00:00:00Z'), [$this->definition('task.one', '* * * * *', ScheduledAction::callback(static function () use (&$calls): void {
            ++$calls;
        }))]);
        try {
            $runner->runTick(new DateTimeImmutable('2026-01-01T00:01:00Z'));
            self::fail('Future checkpoint accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $calls);
        }
    }

    public function test_tick_reads_clock_exactly_once_for_all_due_schedules(): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())->method('now')->willReturn(new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $runner = new SchedulerRunner(new ScheduleRegistry([
            $this->definition('task.a', '* * * * *', ScheduledAction::callback(static fn(): null => null)),
            $this->definition('task.b', '* * * * *', ScheduledAction::callback(static fn(): null => null)),
        ]), $clock, $this->orchestrator());
        self::assertCount(2, $runner->runTick()->runs());
    }

    public function test_prevent_overlap_requires_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runner(new DateTimeImmutable('2026-01-01T00:00:00Z'), [$this->definition('task.one', '* * * * *', ScheduledAction::callback(static fn(): null => null), overlap: OverlapProtection::prevent(new LeaseDuration(1000)))]);
    }

    public function test_contention_skips_action_and_lock_acquisition_is_inside_execution(): void
    {
        $calls = 0;
        $locks = $this->createMock(LockProvider::class);
        $locks->expects(self::once())->method('tryAcquire')->with(self::callback(static fn(LockKey $key): bool => $key->value() === 'scheduler:task.one'), self::isInstanceOf(LeaseDuration::class))->willReturn(null);
        $runner = $this->runner(new DateTimeImmutable('2026-01-01T00:00:00Z'), [$this->definition('task.one', '* * * * *', ScheduledAction::callback(static function () use (&$calls): void {
            ++$calls;
        }), overlap: OverlapProtection::prevent(new LeaseDuration(1000)))], $locks);
        $outcome = $runner->runTick()->runs()[0]->executionOutcome();
        self::assertSame(0, $calls);
        self::assertSame(ExecutionKind::ScheduledJob, $outcome->kind());
        self::assertTrue($outcome->primaryResult()->wasOverlapSkipped());
        $this->expectException(\LogicException::class);
        $outcome->primaryResult()->result();
    }

    public function test_acquired_lease_is_reset_by_core_cleanup(): void
    {
        $events = [];
        $lease = $this->createMock(Lease::class);
        $lease->expects(self::once())->method('reset')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'reset';
        });
        $locks = $this->createStub(LockProvider::class);
        $locks->method('tryAcquire')->willReturn($lease);
        $action = ScheduledAction::callback(static function () use (&$events): string {
            $events[] = 'action';
            return 'ok';
        });
        $runner = $this->runner(new DateTimeImmutable('2026-01-01T00:00:00Z'), [$this->definition('task.one', '* * * * *', $action, overlap: OverlapProtection::prevent(new LeaseDuration(1000)))], $locks);
        $result = $runner->runTick()->runs()[0]->executionOutcome()->primaryResult();
        self::assertSame(['action', 'reset'], $events);
        self::assertSame('ok', $result->result());
    }

    public function test_lock_backend_failure_remains_primary_and_later_task_runs(): void
    {
        $failure = new class ('backend') extends RuntimeException implements LockException {};
        $locks = $this->createStub(LockProvider::class);
        $locks->method('tryAcquire')->willThrowException($failure);
        $runner = $this->runner(new DateTimeImmutable('2026-01-01T00:00:00Z'), [
            $this->definition('task.a', '* * * * *', ScheduledAction::callback(static fn(): null => null), overlap: OverlapProtection::prevent(new LeaseDuration(1000))),
            $this->definition('task.b', '* * * * *', ScheduledAction::callback(static fn(): string => 'later')),
        ], $locks);
        $runs = $runner->runTick()->runs();
        self::assertCount(2, $runs);
        self::assertSame($failure, $runs[0]->executionOutcome()->primaryThrowable());
        self::assertSame('later', $runs[1]->executionOutcome()->primaryResult()->result());
    }

    public function test_cleanup_failure_quarantines_and_stops_later_tasks_before_clock_access(): void
    {
        $lease = $this->createStub(Lease::class);
        $lease->method('reset')->willThrowException(new RuntimeException('cleanup'));
        $locks = $this->createStub(LockProvider::class);
        $locks->method('tryAcquire')->willReturn($lease);
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())->method('now')->willReturn(new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $runner = new SchedulerRunner(new ScheduleRegistry([
            $this->definition('task.a', '* * * * *', ScheduledAction::callback(static fn(): string => 'primary'), overlap: OverlapProtection::prevent(new LeaseDuration(1000))),
            $this->definition('task.b', '* * * * *', ScheduledAction::callback(static fn(): never => throw new RuntimeException('later ran'))),
        ]), $clock, $this->orchestrator(), $locks);
        $report = $runner->runTick();
        self::assertCount(1, $report->runs());
        self::assertSame('primary', $report->runs()[0]->executionOutcome()->primaryResult()->result());
        self::assertTrue($report->runs()[0]->executionOutcome()->cleanupFailed());
        self::assertSame(ProcessReuseDecision::QuarantineRequired, $report->reuseDecision());
        $this->expectException(ExecutionStartFailed::class);
        $runner->runTick();
    }

    public function test_cleanup_failure_keeps_exact_primary_failure(): void
    {
        $primary = new RuntimeException('primary');
        $cleanup = new RuntimeException('cleanup');
        $lease = $this->createStub(Lease::class);
        $lease->method('reset')->willThrowException($cleanup);
        $locks = $this->createStub(LockProvider::class);
        $locks->method('tryAcquire')->willReturn($lease);
        $runner = $this->runner(new DateTimeImmutable('2026-01-01T00:00:00Z'), [
            $this->definition('task.one', '* * * * *', ScheduledAction::callback(static fn(): never => throw $primary), overlap: OverlapProtection::prevent(new LeaseDuration(1000))),
        ], $locks);
        $report = $runner->runTick();
        self::assertSame($primary, $report->runs()[0]->executionOutcome()->primaryThrowable());
        self::assertTrue($report->runs()[0]->executionOutcome()->cleanupFailed());
        self::assertSame(ProcessReuseDecision::QuarantineRequired, $report->reuseDecision());
    }

    public function test_execution_start_failure_is_rethrown_by_identity_and_quarantines(): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())->method('now')->willReturn(new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $runner = new SchedulerRunner(new ScheduleRegistry([$this->definition('task.one', '* * * * *', ScheduledAction::callback(static fn(): null => null))]), $clock, new ExecutionOrchestrator(new ServiceRegistry()));
        try {
            $runner->runTick();
            self::fail('Start failure expected.');
        } catch (ExecutionStartFailed $first) {
            self::assertSame(\Evolve\Core\Exception\ExecutionScopeUnavailable::class, $first->getPrevious()::class);
        }
        $this->expectException(ExecutionStartFailed::class);
        $runner->runTick();
    }

    /** @param list<ScheduleDefinition> $definitions */
    private function runner(DateTimeImmutable $now, array $definitions, ?LockProvider $locks = null): SchedulerRunner
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now);
        return new SchedulerRunner(new ScheduleRegistry($definitions), $clock, $this->orchestrator(), $locks);
    }

    private function orchestrator(): ExecutionOrchestrator
    {
        $services = new ServiceRegistry();
        $services->freeze();
        return new ExecutionOrchestrator($services);
    }

    private function definition(string $id, string $cron, ScheduledAction $action, CatchUpPolicy $policy = CatchUpPolicy::Skip, string $timezone = 'UTC', ?string $locale = null, ?OverlapProtection $overlap = null): ScheduleDefinition
    {
        return new ScheduleDefinition($id, new CronSchedule($cron), $action, $timezone, $locale, $policy, $overlap);
    }
}
