<?php

declare(strict_types=1);

namespace Evolve\Job\Tests\Unit;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionContextValues;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Core\Execution\ProcessReuseDecision;
use Evolve\Job\JobRunner;
use Evolve\Job\JobSettlementState;
use Evolve\Queue\Contracts\Delivery;
use Evolve\Queue\Contracts\Exception\QueueException;
use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueFailureCategory;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueueOperation;
use Evolve\Queue\Contracts\QueueReceiver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JobRunnerTest extends TestCase
{
    public function test_idle_receives_once_without_execution_or_settlement(): void
    {
        $calls = 0;
        $runner = new JobRunner($this->receiver(static function () use (&$calls): ?Delivery {
            ++$calls;
            return null;
        }), $this->orchestrator());
        $outcome = $runner->runOnce(new QueueName('jobs'), static function (): never {
            throw new RuntimeException('handler called');
        });

        self::assertSame(1, $calls);
        self::assertTrue($outcome->isIdle());
        self::assertNull($outcome->executionOutcome());
        self::assertSame(JobSettlementState::NotAttempted, $outcome->settlement());
        self::assertSame(ProcessReuseDecision::Reusable, $outcome->reuseDecision());
    }

    public function test_receive_failure_preserves_exact_queue_exception_without_quarantine(): void
    {
        $failure = $this->queueFailure(QueueOperation::Receive);
        $runner = new JobRunner($this->receiver(static function () use ($failure): never {
            throw $failure;
        }), $this->orchestrator());
        $outcome = $runner->runOnce(new QueueName('jobs'), static function (): never {
            throw new RuntimeException('handler called');
        });

        self::assertSame($failure, $outcome->queueFailure());
        self::assertNull($outcome->executionOutcome());
        self::assertSame(JobSettlementState::NotAttempted, $outcome->settlement());
        self::assertSame(ProcessReuseDecision::Reusable, $outcome->reuseDecision());
    }

    public function test_success_acknowledges_once_after_cleanup_and_preserves_result_and_context(): void
    {
        $events = [];
        $message = new MessageEnvelope('opaque');
        $delivery = $this->delivery($message, $events);
        $values = new ExecutionContextValues('en-NG', 'Africa/Lagos');
        $runner = new JobRunner($this->receiver(static fn(): Delivery => $delivery), $this->orchestrator());
        $result = new \stdClass();

        $outcome = $runner->runOnce(new QueueName('jobs'), function (MessageEnvelope $received, ExecutionContext $context, ExecutionScope $scope) use ($message, $values, $result, &$events): object {
            self::assertSame($message, $received);
            self::assertSame($values, $context->values());
            self::assertSame(ExecutionKind::QueueMessage, $context->kind());
            $events[] = 'handle';
            $scope->registerResetParticipant('marker', $this->participant(static function () use (&$events): void {
                $events[] = 'cleanup';
            }));
            return $result;
        }, $values);

        self::assertSame(['handle', 'cleanup', 'acknowledge'], $events);
        self::assertSame($result, $outcome->executionOutcome()->primaryResult());
        self::assertSame(JobSettlementState::Acknowledged, $outcome->settlement());
        self::assertSame(ProcessReuseDecision::Reusable, $outcome->reuseDecision());
    }

    public function test_handler_failure_rejects_once_after_cleanup_and_preserves_exact_throwable(): void
    {
        $events = [];
        $failure = new RuntimeException('original');
        $delivery = $this->delivery(new MessageEnvelope('opaque'), $events);
        $runner = new JobRunner($this->receiver(static fn(): Delivery => $delivery), $this->orchestrator());

        $outcome = $runner->runOnce(new QueueName('jobs'), function (MessageEnvelope $message, ExecutionContext $context, ExecutionScope $scope) use ($failure, &$events): never {
            $scope->registerResetParticipant('marker', $this->participant(static function () use (&$events): void {
                $events[] = 'cleanup';
            }));
            throw $failure;
        });

        self::assertSame(['cleanup', 'reject'], $events);
        self::assertSame($failure, $outcome->executionOutcome()->primaryThrowable());
        self::assertSame(JobSettlementState::Rejected, $outcome->settlement());
        self::assertSame(ProcessReuseDecision::Reusable, $outcome->reuseDecision());
    }

    public function test_cleanup_failure_prevents_settlement_and_quarantines_runner(): void
    {
        $events = [];
        $delivery = $this->delivery(new MessageEnvelope('opaque'), $events);
        $receives = 0;
        $runner = new JobRunner($this->receiver(static function () use ($delivery, &$receives): Delivery {
            ++$receives;
            return $delivery;
        }), $this->orchestrator());
        $failure = new RuntimeException('reset');

        $outcome = $runner->runOnce(new QueueName('jobs'), function (MessageEnvelope $message, ExecutionContext $context, ExecutionScope $scope) use ($failure): string {
            $scope->registerResetParticipant('broken', $this->participant(static function () use ($failure): never {
                throw $failure;
            }));
            return 'primary';
        });

        self::assertSame('primary', $outcome->executionOutcome()->primaryResult());
        self::assertTrue($outcome->executionOutcome()->cleanupFailed());
        self::assertSame([], $events);
        self::assertSame(JobSettlementState::NotAttempted, $outcome->settlement());
        self::assertSame(ProcessReuseDecision::QuarantineRequired, $outcome->reuseDecision());
        try {
            $runner->runOnce(new QueueName('jobs'), static fn(): null => null);
            self::fail('quarantine expected');
        } catch (ExecutionStartFailed) {
            self::assertSame(1, $receives);
        }
    }

    public function test_settlement_failures_are_not_retried_and_quarantine_runner(): void
    {
        foreach ([QueueOperation::Acknowledge, QueueOperation::Reject] as $operation) {
            $events = [];
            $failure = $this->queueFailure($operation);
            $delivery = $this->delivery(new MessageEnvelope('opaque'), $events, $failure);
            $runner = new JobRunner($this->receiver(static fn(): Delivery => $delivery), $this->orchestrator());
            $primary = new RuntimeException('handler');
            $outcome = $runner->runOnce(new QueueName('jobs'), static function () use ($operation, $primary): mixed {
                if ($operation === QueueOperation::Reject) {
                    throw $primary;
                }
                return 'ok';
            });

            self::assertSame($failure, $outcome->queueFailure());
            self::assertSame([$operation === QueueOperation::Acknowledge ? 'acknowledge' : 'reject'], $events);
            self::assertSame($operation === QueueOperation::Acknowledge ? JobSettlementState::AcknowledgeFailed : JobSettlementState::RejectFailed, $outcome->settlement());
            self::assertSame(ProcessReuseDecision::QuarantineRequired, $outcome->reuseDecision());
            if ($operation === QueueOperation::Reject) {
                self::assertSame($primary, $outcome->executionOutcome()->primaryThrowable());
            }
            $this->expectQuarantined($runner);
        }
    }

    public function test_repeated_clean_jobs_have_fresh_context_scope_and_no_process_global_mutation(): void
    {
        $events = [];
        $delivery = $this->delivery(new MessageEnvelope('opaque'), $events);
        $runner = new JobRunner($this->receiver(static fn(): Delivery => $delivery), $this->orchestrator());
        $seen = [];
        $timezone = date_default_timezone_get();
        $locale = setlocale(LC_ALL, '0');
        $intl = class_exists(\Locale::class) ? \Locale::getDefault() : null;
        $handler = static function (MessageEnvelope $message, ExecutionContext $context, ExecutionScope $scope) use (&$seen): void {
            $seen[] = [$context, $scope, $context->locale(), $context->timezone()];
        };

        $first = $runner->runOnce(new QueueName('jobs'), $handler, new ExecutionContextValues('fr-FR', 'Europe/Paris'));
        $second = $runner->runOnce(new QueueName('jobs'), $handler);

        self::assertNotSame($first->executionOutcome()->identifier()->value(), $second->executionOutcome()->identifier()->value());
        self::assertNotSame($seen[0][1], $seen[1][1]);
        self::assertSame(['fr-FR', 'Europe/Paris'], array_slice($seen[0], 2));
        self::assertSame([null, null], array_slice($seen[1], 2));
        self::assertSame($timezone, date_default_timezone_get());
        self::assertSame($locale, setlocale(LC_ALL, '0'));
        if ($intl !== null) {
            self::assertSame($intl, \Locale::getDefault());
        }
    }

    public function test_start_failure_after_receive_is_rethrown_exactly_without_settlement_and_quarantines(): void
    {
        $events = [];
        $receives = 0;
        $delivery = $this->delivery(new MessageEnvelope('opaque'), $events);
        $runner = new JobRunner($this->receiver(static function () use ($delivery, &$receives): Delivery {
            ++$receives;
            return $delivery;
        }), new ExecutionOrchestrator(new ServiceRegistry()));
        $caught = null;
        try {
            $runner->runOnce(new QueueName('jobs'), static fn(): null => null);
        } catch (ExecutionStartFailed $failure) {
            $caught = $failure;
        }

        self::assertInstanceOf(ExecutionStartFailed::class, $caught);
        self::assertSame([], $events);
        self::assertSame(1, $receives);
        try {
            $runner->runOnce(new QueueName('jobs'), static fn(): null => null);
            self::fail('quarantine expected');
        } catch (ExecutionStartFailed $later) {
            self::assertNotSame($caught, $later);
        }
    }

    private function orchestrator(): ExecutionOrchestrator
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        return new ExecutionOrchestrator($registry);
    }

    /** @param callable(QueueName): ?Delivery $callback */
    private function receiver(callable $callback): QueueReceiver
    {
        return new class (\Closure::fromCallable($callback)) implements QueueReceiver {
            public function __construct(private \Closure $callback) {}
            public function receive(QueueName $queue): ?Delivery
            {
                return ($this->callback)($queue);
            }
        };
    }

    /**
     * @param list<string> $events
     * @param-out list<string> $events
     */
    private function delivery(MessageEnvelope $message, array &$events, ?QueueException $failure = null): Delivery
    {
        return new class ($message, $events, $failure) implements Delivery {
            /** @param list<string> $events */
            public function __construct(private MessageEnvelope $message, public array &$events, private ?QueueException $failure) {}
            public function message(): MessageEnvelope
            {
                return $this->message;
            }
            public function acknowledge(): void
            {
                $this->events[] = 'acknowledge';
                if ($this->failure !== null) {
                    throw $this->failure;
                }
            }
            public function reject(): void
            {
                $this->events[] = 'reject';
                if ($this->failure !== null) {
                    throw $this->failure;
                }
            }
        };
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

    /** @param callable(): void $callback */
    private function participant(callable $callback): ResetParticipant
    {
        return new class (\Closure::fromCallable($callback)) implements ResetParticipant {
            public function __construct(private \Closure $callback) {}
            public function reset(): void
            {
                ($this->callback)();
            }
        };
    }

    private function expectQuarantined(JobRunner $runner): void
    {
        try {
            $runner->runOnce(new QueueName('jobs'), static fn(): null => null);
            self::fail('quarantine expected');
        } catch (ExecutionStartFailed) {
            return;
        }
    }
}
