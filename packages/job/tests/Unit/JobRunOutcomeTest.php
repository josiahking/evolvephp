<?php

declare(strict_types=1);

namespace Evolve\Job\Tests\Unit;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Core\Execution\ProcessReuseDecision;
use Evolve\Job\JobRunOutcome;
use Evolve\Job\JobSettlementState;
use Evolve\Queue\Contracts\Exception\QueueException;
use Evolve\Queue\Contracts\QueueFailureCategory;
use Evolve\Queue\Contracts\QueueOperation;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class JobRunOutcomeTest extends TestCase
{
    public function test_idle_is_the_only_empty_reusable_state(): void
    {
        $outcome = JobRunOutcome::idle();

        self::assertNull($outcome->executionOutcome());
        self::assertNull($outcome->queueFailure());
        self::assertSame(JobSettlementState::NotAttempted, $outcome->settlement());
        self::assertSame(ProcessReuseDecision::Reusable, $outcome->reuseDecision());
        self::assertTrue($outcome->isIdle());
        self::assertFalse((new ReflectionClass(JobRunOutcome::class))->getConstructor()->isPublic());
    }

    public function test_executed_state_rejects_impossible_settlement_combinations(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $execution = (new ExecutionOrchestrator($registry))->execute(ExecutionKind::QueueMessage, static fn(): string => 'ok');

        $this->expectException(LogicException::class);
        JobRunOutcome::executed($execution, JobSettlementState::Rejected);
    }

    #[DataProvider('invalidReceiveOperations')]
    public function test_receive_failure_rejects_other_operations(QueueOperation $operation): void
    {
        $this->expectException(LogicException::class);
        JobRunOutcome::receiveFailed($this->queueFailure($operation));
    }

    /** @return iterable<string, array{QueueOperation}> */
    public static function invalidReceiveOperations(): iterable
    {
        yield 'publish' => [QueueOperation::Publish];
        yield 'acknowledge' => [QueueOperation::Acknowledge];
        yield 'reject' => [QueueOperation::Reject];
    }

    public function test_receive_failure_accepts_receive_operation(): void
    {
        $failure = $this->queueFailure(QueueOperation::Receive);
        $outcome = JobRunOutcome::receiveFailed($failure);

        self::assertSame($failure, $outcome->queueFailure());
        self::assertNull($outcome->executionOutcome());
        self::assertSame(JobSettlementState::NotAttempted, $outcome->settlement());
        self::assertSame(ProcessReuseDecision::Reusable, $outcome->reuseDecision());
        self::assertFalse($outcome->isIdle());
    }

    #[DataProvider('validSettlementFailures')]
    public function test_failed_settlement_accepts_matching_operation(JobSettlementState $settlement, QueueOperation $operation, bool $primarySucceeds): void
    {
        $failure = $this->queueFailure($operation);
        $execution = $this->execution($primarySucceeds);
        $outcome = JobRunOutcome::executed($execution, $settlement, $failure);

        self::assertSame($execution, $outcome->executionOutcome());
        self::assertSame($failure, $outcome->queueFailure());
        self::assertSame($settlement, $outcome->settlement());
        self::assertSame(ProcessReuseDecision::QuarantineRequired, $outcome->reuseDecision());
    }

    /** @return iterable<string, array{JobSettlementState, QueueOperation, bool}> */
    public static function validSettlementFailures(): iterable
    {
        yield 'acknowledge' => [JobSettlementState::AcknowledgeFailed, QueueOperation::Acknowledge, true];
        yield 'reject' => [JobSettlementState::RejectFailed, QueueOperation::Reject, false];
    }

    #[DataProvider('invalidSettlementOperations')]
    public function test_failed_settlement_rejects_mismatched_operation(JobSettlementState $settlement, QueueOperation $operation, bool $primarySucceeds): void
    {
        $this->expectException(LogicException::class);
        JobRunOutcome::executed($this->execution($primarySucceeds), $settlement, $this->queueFailure($operation));
    }

    /** @return iterable<string, array{JobSettlementState, QueueOperation, bool}> */
    public static function invalidSettlementOperations(): iterable
    {
        yield 'acknowledge with receive' => [JobSettlementState::AcknowledgeFailed, QueueOperation::Receive, true];
        yield 'acknowledge with reject' => [JobSettlementState::AcknowledgeFailed, QueueOperation::Reject, true];
        yield 'reject with receive' => [JobSettlementState::RejectFailed, QueueOperation::Receive, false];
        yield 'reject with acknowledge' => [JobSettlementState::RejectFailed, QueueOperation::Acknowledge, false];
    }

    private function execution(bool $primarySucceeds): ExecutionOutcome
    {
        $registry = new ServiceRegistry();
        $registry->freeze();

        return (new ExecutionOrchestrator($registry))->execute(
            ExecutionKind::QueueMessage,
            static function () use ($primarySucceeds): string {
                if (!$primarySucceeds) {
                    throw new RuntimeException('handler failure');
                }

                return 'ok';
            },
        );
    }

    private function queueFailure(QueueOperation $operation): QueueException
    {
        return new class ($operation) extends RuntimeException implements QueueException {
            public function __construct(private QueueOperation $operation)
            {
                parent::__construct('safe queue failure');
            }

            public function operation(): QueueOperation
            {
                return $this->operation;
            }

            public function category(): QueueFailureCategory
            {
                return QueueFailureCategory::Transport;
            }
        };
    }
}
