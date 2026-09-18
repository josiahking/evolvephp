<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Instrumentation;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionCleanupFailed;
use Evolve\Core\Exception\ExecutionResetFailed;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionContextAttacher;
use Evolve\Core\Execution\ExecutionContextAttachment;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Core\Execution\ProcessReuseDecision;
use Evolve\Core\Instrumentation\InstrumentationFailure;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationDispatcher;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Core\Instrumentation\ObservationSink;
use Evolve\Core\Instrumentation\ObservationType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Throwable;
use WeakReference;

final class ExecutionInstrumentationTest extends TestCase
{
    public function test_optional_instrumentation_preserves_phase35_execution_behavior(): void
    {
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry());

        $outcome = $orchestrator->execute(
            ExecutionKind::WorkerTask,
            static fn(): string => 'handled',
        );

        self::assertTrue($outcome->primarySucceeded());
        self::assertSame('handled', $outcome->primaryResult());
        self::assertFalse($outcome->cleanupFailed());
        self::assertSame(ProcessReuseDecision::Reusable, $outcome->reuseDecision());
        self::assertFalse($outcome->instrumentationFailed());
        self::assertSame([], $outcome->instrumentationFailures());
    }

    public function test_multiple_observation_sinks_receive_same_observations_in_configuration_order(): void
    {
        $events = [];
        $first = new RecordingObservationSink('first', $events);
        $second = new RecordingObservationSink('second', $events);

        (new ExecutionOrchestrator($this->frozenRegistry(), [$first, $second]))->execute(
            ExecutionKind::HttpRequest,
            static fn(): string => 'handled',
        );

        self::assertSame(
            [
                'first:ExecutionStarted',
                'second:ExecutionStarted',
                'first:HandlerCompleted',
                'second:HandlerCompleted',
                'first:ScopeCloseStarted',
                'second:ScopeCloseStarted',
                'first:ScopeCloseCompleted',
                'second:ScopeCloseCompleted',
                'first:ExecutionCompleted',
                'second:ExecutionCompleted',
            ],
            $events,
        );

        self::assertSame($first->types(), $second->types());
        self::assertSame($first->identifierValues(), $second->identifierValues());
    }

    public function test_throwing_observation_sink_does_not_stop_later_sinks_and_all_failures_are_retained_in_order(): void
    {
        $events = [];
        $first = new RecordingThrowingObservationSink('first', $events, [ObservationType::ExecutionStarted]);
        $second = new RecordingObservationSink('second', $events);
        $third = new RecordingThrowingObservationSink('third', $events, [ObservationType::ExecutionStarted]);

        $outcome = (new ExecutionOrchestrator($this->frozenRegistry(), [$first, $second, $third]))->execute(
            ExecutionKind::WorkerTask,
            static fn(): string => 'handled',
        );

        self::assertSame(
            [
                'first:ExecutionStarted',
                'second:ExecutionStarted',
                'third:ExecutionStarted',
            ],
            array_slice($events, 0, 3),
        );
        self::assertTrue($outcome->instrumentationFailed());
        self::assertSame(
            [
                ObservationType::ExecutionStarted,
                ObservationType::ExecutionStarted,
            ],
            $this->instrumentationFailureTypes($outcome->instrumentationFailures()),
        );
        self::assertSame(
            [
                RuntimeException::class,
                RuntimeException::class,
            ],
            $this->instrumentationFailureErrorTypes($outcome->instrumentationFailures()),
        );
        self::assertTrue($outcome->isReusable());
    }

    public function test_observation_dispatcher_rejects_invalid_sink_arrays_and_duplicate_instances(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ObservationDispatcher([new CollectingObservationSink(), new \stdClass()]);
    }

    public function test_observation_dispatcher_rejects_duplicate_sink_object_instances(): void
    {
        $sink = new CollectingObservationSink();

        $this->expectException(InvalidArgumentException::class);

        new ObservationDispatcher([$sink, $sink]);
    }

    public function test_existing_single_sink_and_no_sink_configuration_remain_compatible(): void
    {
        $sink = new CollectingObservationSink();

        $withSink = (new ExecutionOrchestrator($this->frozenRegistry(), $sink))->execute(
            ExecutionKind::HttpRequest,
            static fn(): string => 'with-sink',
        );
        $withoutSink = (new ExecutionOrchestrator($this->frozenRegistry(), null))->execute(
            ExecutionKind::HttpRequest,
            static fn(): string => 'without-sink',
        );

        self::assertSame('with-sink', $withSink->primaryResult());
        self::assertSame('without-sink', $withoutSink->primaryResult());
        self::assertSame(
            [
                ObservationType::ExecutionStarted,
                ObservationType::HandlerCompleted,
                ObservationType::ScopeCloseStarted,
                ObservationType::ScopeCloseCompleted,
                ObservationType::ExecutionCompleted,
            ],
            $sink->types(),
        );
        self::assertSame([], $withoutSink->instrumentationFailures());
    }

    public function test_context_attachers_attach_in_order_and_detach_in_reverse_before_scope_reset(): void
    {
        $events = [];
        $sink = new RecordingObservationSink('observe', $events);
        $first = new RecordingExecutionContextAttacher('first', $events);
        $second = new RecordingExecutionContextAttacher('second', $events);

        $outcome = (new ExecutionOrchestrator($this->frozenRegistry(), $sink, [$first, $second]))->execute(
            ExecutionKind::ScheduledJob,
            function (ExecutionContext $context, ExecutionScope $scope) use (&$events): string {
                $scope->registerResetParticipant('reset', $this->participant(static function () use (&$events): void {
                    $events[] = 'reset';
                }));

                return 'handled';
            },
        );

        self::assertSame('handled', $outcome->primaryResult());
        self::assertSame(
            [
                'first:attach',
                'second:attach',
                'observe:ExecutionStarted',
                'observe:HandlerCompleted',
                'observe:ScopeCloseStarted',
                'second:detach',
                'first:detach',
                'reset',
                'observe:ScopeCloseCompleted',
                'observe:ExecutionCompleted',
            ],
            $events,
        );
    }

    public function test_attach_failure_is_instrumentation_failure_without_quarantine_and_later_attachers_still_run(): void
    {
        $events = [];
        $failing = new ThrowingExecutionContextAttacher('failing', $events);
        $later = new RecordingExecutionContextAttacher('later', $events);
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry(), null, [$failing, $later]);

        $outcome = $orchestrator->execute(
            ExecutionKind::QueueMessage,
            static fn(): string => 'handled',
        );
        $second = $orchestrator->execute(
            ExecutionKind::QueueMessage,
            static fn(): string => 'second',
        );

        self::assertSame('handled', $outcome->primaryResult());
        self::assertTrue($outcome->instrumentationFailed());
        self::assertSame([ObservationType::ExecutionStarted], $this->instrumentationFailureTypes($outcome->instrumentationFailures()));
        self::assertSame([RuntimeException::class], $this->instrumentationFailureErrorTypes($outcome->instrumentationFailures()));
        self::assertTrue($outcome->isReusable());
        self::assertTrue($second->isReusable());
        self::assertSame(
            [
                'failing:attach',
                'later:attach',
                'later:detach',
                'failing:attach',
                'later:attach',
                'later:detach',
            ],
            $events,
        );
    }

    public function test_detach_failure_preserves_successful_primary_result_detaches_remaining_attachments_and_quarantines(): void
    {
        $events = [];
        $detachFailure = new RuntimeException('secret detach token');
        $first = new RecordingExecutionContextAttacher('first', $events);
        $second = new RecordingExecutionContextAttacher('second', $events, $detachFailure);
        $third = new RecordingExecutionContextAttacher('third', $events);
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry(), null, [$first, $second, $third]);

        $outcome = $orchestrator->execute(
            ExecutionKind::WorkerTask,
            function (ExecutionContext $context, ExecutionScope $scope) use (&$events): string {
                $scope->registerResetParticipant('reset', $this->participant(static function () use (&$events): void {
                    $events[] = 'reset';
                }));

                return 'primary-result';
            },
        );

        self::assertSame('primary-result', $outcome->primaryResult());
        self::assertInstanceOf(ExecutionCleanupFailed::class, $outcome->cleanupThrowable());
        self::assertSame([$detachFailure], $outcome->cleanupThrowable()->failures());
        self::assertTrue($outcome->requiresQuarantine());
        self::assertSame(
            [
                'first:attach',
                'second:attach',
                'third:attach',
                'third:detach',
                'second:detach',
                'first:detach',
                'reset',
            ],
            $events,
        );

        $this->expectException(\Evolve\Core\Exception\ExecutionStartFailed::class);

        $orchestrator->execute(ExecutionKind::WorkerTask, static fn(): string => 'later');
    }

    public function test_detach_failure_preserves_failed_primary_throwable_and_reports_cleanup_observations(): void
    {
        $events = [];
        $sink = new CollectingObservationSink();
        $handlerFailure = new RuntimeException('secret handler token');
        $detachFailure = new RuntimeException('secret detach token');
        $attacher = new RecordingExecutionContextAttacher('context', $events, $detachFailure);

        $outcome = (new ExecutionOrchestrator($this->frozenRegistry(), $sink, [$attacher]))->execute(
            ExecutionKind::CliCommand,
            static function () use ($handlerFailure): never {
                throw $handlerFailure;
            },
        );

        self::assertSame($handlerFailure, $outcome->primaryThrowable());
        self::assertInstanceOf(ExecutionCleanupFailed::class, $outcome->cleanupThrowable());
        self::assertSame([$detachFailure], $outcome->cleanupThrowable()->failures());
        self::assertSame(ProcessReuseDecision::QuarantineRequired, $outcome->reuseDecision());
        self::assertSame(ObservationOutcome::Failed, $sink->observations()[3]->outcome());
        self::assertSame(ExecutionCleanupFailed::class, $sink->observations()[3]->errorType());
        self::assertSame(ObservationType::QuarantineRequired, $sink->observations()[4]->type());
        self::assertSame(ObservationOutcome::Failed, $sink->observations()[5]->outcome());
    }

    public function test_combined_detach_and_scope_reset_failures_are_aggregated_in_order(): void
    {
        $events = [];
        $firstDetachFailure = new RuntimeException('first detach');
        $secondDetachFailure = new RuntimeException('second detach');
        $scopeResetFailure = new RuntimeException('reset');
        $first = new RecordingExecutionContextAttacher('first', $events, $firstDetachFailure);
        $second = new RecordingExecutionContextAttacher('second', $events, $secondDetachFailure);

        $outcome = (new ExecutionOrchestrator($this->frozenRegistry(), null, [$first, $second]))->execute(
            ExecutionKind::HttpRequest,
            function (ExecutionContext $context, ExecutionScope $scope) use ($scopeResetFailure): string {
                $scope->registerResetParticipant('broken', $this->participant(static function () use ($scopeResetFailure): never {
                    throw $scopeResetFailure;
                }));

                return 'handled';
            },
        );

        self::assertInstanceOf(ExecutionCleanupFailed::class, $outcome->cleanupThrowable());
        self::assertCount(3, $outcome->cleanupThrowable()->failures());
        self::assertSame($secondDetachFailure, $outcome->cleanupThrowable()->failures()[0]);
        self::assertSame($firstDetachFailure, $outcome->cleanupThrowable()->failures()[1]);
        self::assertInstanceOf(ExecutionResetFailed::class, $outcome->cleanupThrowable()->failures()[2]);
        self::assertSame([$scopeResetFailure], $outcome->cleanupThrowable()->failures()[2]->failures());
    }

    public function test_scope_only_reset_failure_keeps_existing_execution_reset_failed_cleanup_type(): void
    {
        $events = [];
        $resetFailure = new RuntimeException('reset');

        $outcome = (new ExecutionOrchestrator($this->frozenRegistry(), null, [new RecordingExecutionContextAttacher('context', $events)]))->execute(
            ExecutionKind::HttpRequest,
            function (ExecutionContext $context, ExecutionScope $scope) use ($resetFailure): string {
                $scope->registerResetParticipant('broken', $this->participant(static function () use ($resetFailure): never {
                    throw $resetFailure;
                }));

                return 'handled';
            },
        );

        self::assertInstanceOf(ExecutionResetFailed::class, $outcome->cleanupThrowable());
        self::assertSame([$resetFailure], $outcome->cleanupThrowable()->failures());
    }

    public function test_repeated_successful_executions_receive_fresh_attachments(): void
    {
        $events = [];
        $attacher = new RecordingExecutionContextAttacher('context', $events);
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry(), null, [$attacher]);

        $first = $orchestrator->execute(ExecutionKind::WorkerTask, static fn(): string => 'first');
        $second = $orchestrator->execute(ExecutionKind::WorkerTask, static fn(): string => 'second');

        self::assertSame('first', $first->primaryResult());
        self::assertSame('second', $second->primaryResult());
        self::assertNotSame($first->identifier()->value(), $second->identifier()->value());
        self::assertSame(
            [
                'context:attach',
                'context:detach',
                'context:attach',
                'context:detach',
            ],
            $events,
        );
        self::assertSame(2, $attacher->attachmentCount());
    }

    public function test_execution_orchestrator_rejects_invalid_attacher_arrays_and_duplicate_instances(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExecutionOrchestrator($this->frozenRegistry(), null, [new \stdClass()]);
    }

    public function test_execution_orchestrator_rejects_duplicate_attacher_instances(): void
    {
        $events = [];
        $attacher = new RecordingExecutionContextAttacher('context', $events);

        $this->expectException(InvalidArgumentException::class);

        new ExecutionOrchestrator($this->frozenRegistry(), null, [$attacher, $attacher]);
    }

    public function test_successful_execution_emits_safe_ordered_observations(): void
    {
        $sink = new CollectingObservationSink();
        $outcome = (new ExecutionOrchestrator($this->frozenRegistry(), $sink))->execute(
            ExecutionKind::HttpRequest,
            static fn(): object => new \stdClass(),
        );

        self::assertSame(
            [
                ObservationType::ExecutionStarted,
                ObservationType::HandlerCompleted,
                ObservationType::ScopeCloseStarted,
                ObservationType::ScopeCloseCompleted,
                ObservationType::ExecutionCompleted,
            ],
            $sink->types(),
        );

        self::assertSame(ObservationOutcome::Succeeded, $sink->observations()[1]->outcome());
        self::assertNull($sink->observations()[1]->errorType());
        self::assertSame(ObservationOutcome::Succeeded, $sink->observations()[3]->outcome());
        self::assertSame(ObservationOutcome::Succeeded, $sink->observations()[4]->outcome());
        self::assertSame(ProcessReuseDecision::Reusable, $sink->observations()[4]->reuseDecision());

        foreach ($sink->observations() as $observation) {
            self::assertTrue($observation->identifier()->equals($outcome->identifier()));
            self::assertSame(ExecutionKind::HttpRequest, $observation->kind());
        }

        $this->assertObservationPayloadIsSafe();
    }

    public function test_handler_failure_sequence_preserves_original_throwable_and_safe_error_type(): void
    {
        $sink = new CollectingObservationSink();
        $handlerFailure = new RuntimeException('secret handler token');

        $outcome = (new ExecutionOrchestrator($this->frozenRegistry(), $sink))->execute(
            ExecutionKind::CliCommand,
            static function () use ($handlerFailure): never {
                throw $handlerFailure;
            },
        );

        self::assertSame(
            [
                ObservationType::ExecutionStarted,
                ObservationType::HandlerCompleted,
                ObservationType::ScopeCloseStarted,
                ObservationType::ScopeCloseCompleted,
                ObservationType::ExecutionCompleted,
            ],
            $sink->types(),
        );
        self::assertSame($handlerFailure, $outcome->primaryThrowable());
        self::assertSame(ObservationOutcome::Failed, $sink->observations()[1]->outcome());
        self::assertSame(RuntimeException::class, $sink->observations()[1]->errorType());
        self::assertStringNotContainsString('secret handler token', implode('|', $sink->errorTypes()));
        self::assertSame(ObservationOutcome::Succeeded, $sink->observations()[3]->outcome());
        self::assertSame(ObservationOutcome::Failed, $sink->observations()[4]->outcome());
        self::assertSame(ProcessReuseDecision::Reusable, $sink->observations()[4]->reuseDecision());
    }

    public function test_cleanup_failure_sequence_preserves_primary_result_and_reports_quarantine_after_decision(): void
    {
        $sink = new CollectingObservationSink();
        $cleanupFailure = new RuntimeException('secret cleanup token');

        $outcome = (new ExecutionOrchestrator($this->frozenRegistry(), $sink))->execute(
            ExecutionKind::ScheduledJob,
            function (ExecutionContext $context, ExecutionScope $scope) use ($cleanupFailure): string {
                $scope->registerResetParticipant('broken', $this->participant(static function () use ($cleanupFailure): never {
                    throw $cleanupFailure;
                }));

                return 'primary-result';
            },
        );

        self::assertSame(
            [
                ObservationType::ExecutionStarted,
                ObservationType::HandlerCompleted,
                ObservationType::ScopeCloseStarted,
                ObservationType::ScopeCloseCompleted,
                ObservationType::QuarantineRequired,
                ObservationType::ExecutionCompleted,
            ],
            $sink->types(),
        );
        self::assertSame('primary-result', $outcome->primaryResult());
        self::assertTrue($outcome->cleanupFailed());
        self::assertInstanceOf(ExecutionResetFailed::class, $outcome->cleanupThrowable());
        self::assertSame(ObservationOutcome::Failed, $sink->observations()[3]->outcome());
        self::assertSame(ExecutionResetFailed::class, $sink->observations()[3]->errorType());
        self::assertSame(ProcessReuseDecision::QuarantineRequired, $sink->observations()[4]->reuseDecision());
        self::assertSame(ObservationOutcome::Succeeded, $sink->observations()[5]->outcome());
        self::assertSame(ProcessReuseDecision::QuarantineRequired, $sink->observations()[5]->reuseDecision());
        self::assertStringNotContainsString('secret cleanup token', implode('|', $sink->errorTypes()));
    }

    public function test_handler_cleanup_and_instrumentation_failures_remain_separate(): void
    {
        $handlerFailure = new RuntimeException('secret handler token');
        $cleanupFailure = new RuntimeException('secret cleanup token');
        $sink = new ThrowingObservationSink([
            ObservationType::ExecutionStarted,
            ObservationType::HandlerCompleted,
            ObservationType::ScopeCloseStarted,
            ObservationType::ScopeCloseCompleted,
            ObservationType::QuarantineRequired,
            ObservationType::ExecutionCompleted,
        ]);

        $outcome = (new ExecutionOrchestrator($this->frozenRegistry(), $sink))->execute(
            ExecutionKind::QueueMessage,
            function (ExecutionContext $context, ExecutionScope $scope) use ($handlerFailure, $cleanupFailure): never {
                $scope->registerResetParticipant('broken', $this->participant(static function () use ($cleanupFailure): never {
                    throw $cleanupFailure;
                }));

                throw $handlerFailure;
            },
        );

        self::assertSame($handlerFailure, $outcome->primaryThrowable());
        self::assertInstanceOf(ExecutionResetFailed::class, $outcome->cleanupThrowable());
        self::assertSame([$cleanupFailure], $outcome->cleanupThrowable()->failures());
        self::assertSame(ProcessReuseDecision::QuarantineRequired, $outcome->reuseDecision());
        self::assertTrue($outcome->instrumentationFailed());
        self::assertSame(
            [
                ObservationType::ExecutionStarted,
                ObservationType::HandlerCompleted,
                ObservationType::ScopeCloseStarted,
                ObservationType::ScopeCloseCompleted,
                ObservationType::QuarantineRequired,
                ObservationType::ExecutionCompleted,
            ],
            array_map(
                static fn(InstrumentationFailure $failure): ObservationType => $failure->observationType(),
                $outcome->instrumentationFailures(),
            ),
        );
        self::assertSame(
            [
                ObservationType::ExecutionStarted,
                ObservationType::HandlerCompleted,
                ObservationType::ScopeCloseStarted,
                ObservationType::ScopeCloseCompleted,
                ObservationType::QuarantineRequired,
                ObservationType::ExecutionCompleted,
            ],
            $sink->types(),
        );
        self::assertStringNotContainsString('secret', implode('|', $this->instrumentationFailureErrorTypes($outcome->instrumentationFailures())));
    }

    public function test_failure_matrix_preserves_primary_cleanup_instrumentation_and_reuse_channels(): void
    {
        foreach ([false, true] as $handlerFails) {
            foreach ([false, true] as $cleanupFails) {
                foreach ([false, true] as $instrumentationFails) {
                    $handlerFailure = new RuntimeException('handler');
                    $cleanupFailure = new RuntimeException('cleanup');
                    $sink = $instrumentationFails
                        ? new ThrowingObservationSink(ObservationType::cases())
                        : new CollectingObservationSink();

                    $outcome = (new ExecutionOrchestrator($this->frozenRegistry(), $sink))->execute(
                        ExecutionKind::WorkerTask,
                        function (ExecutionContext $context, ExecutionScope $scope) use ($handlerFails, $cleanupFails, $handlerFailure, $cleanupFailure): string {
                            if ($cleanupFails) {
                                $scope->registerResetParticipant('broken', $this->participant(static function () use ($cleanupFailure): never {
                                    throw $cleanupFailure;
                                }));
                            }

                            if ($handlerFails) {
                                throw $handlerFailure;
                            }

                            return 'ok';
                        },
                    );

                    self::assertSame(! $handlerFails, $outcome->primarySucceeded());
                    self::assertSame($handlerFails, $outcome->primaryFailed());
                    self::assertSame($cleanupFails, $outcome->cleanupFailed());
                    self::assertSame($instrumentationFails, $outcome->instrumentationFailed());
                    self::assertSame(
                        $cleanupFails ? ProcessReuseDecision::QuarantineRequired : ProcessReuseDecision::Reusable,
                        $outcome->reuseDecision(),
                    );

                    if ($handlerFails) {
                        self::assertSame($handlerFailure, $outcome->primaryThrowable());
                    } else {
                        self::assertSame('ok', $outcome->primaryResult());
                    }

                    if ($cleanupFails) {
                        self::assertInstanceOf(ExecutionResetFailed::class, $outcome->cleanupThrowable());
                        self::assertSame([$cleanupFailure], $outcome->cleanupThrowable()->failures());
                    } else {
                        self::assertNull($outcome->cleanupThrowable());
                    }
                }
            }
        }
    }

    public function test_instrumentation_failure_alone_does_not_quarantine_or_contaminate_later_execution(): void
    {
        $sink = new ThrowingObservationSink([ObservationType::ExecutionStarted], 1);
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry(), $sink);
        $calls = 0;

        $first = $orchestrator->execute(
            ExecutionKind::HttpRequest,
            static function () use (&$calls): string {
                ++$calls;

                return 'first';
            },
        );
        $second = $orchestrator->execute(
            ExecutionKind::HttpRequest,
            static function () use (&$calls): string {
                ++$calls;

                return 'second';
            },
        );

        self::assertSame(2, $calls);
        self::assertTrue($first->primarySucceeded());
        self::assertTrue($first->isReusable());
        self::assertTrue($first->instrumentationFailed());
        self::assertSame([ObservationType::ExecutionStarted], $this->instrumentationFailureTypes($first->instrumentationFailures()));
        self::assertFalse($second->instrumentationFailed());
        self::assertTrue($second->isReusable());
        self::assertNotSame($first->identifier()->value(), $second->identifier()->value());

        $firstIdentifier = $first->identifier()->value();
        $secondIdentifier = $second->identifier()->value();
        self::assertSame([$firstIdentifier], array_unique(array_slice($sink->identifierValues(), 0, 5)));
        self::assertSame([$secondIdentifier], array_unique(array_slice($sink->identifierValues(), 5, 5)));
    }

    public function test_instrumentation_does_not_retain_execution_scoped_services(): void
    {
        $weak = null;
        $registry = new ServiceRegistry();
        $registry->register('execution.object', ServiceLifetime::Execution, static function () use (&$weak): object {
            $service = new \stdClass();
            $weak = WeakReference::create($service);

            return $service;
        });
        $registry->freeze();

        $sink = new CollectingObservationSink();
        (new ExecutionOrchestrator($registry, $sink))->execute(
            ExecutionKind::HttpRequest,
            static function (ExecutionContext $context, ExecutionScope $scope): string {
                $scope->get('execution.object');

                return 'done';
            },
        );

        gc_collect_cycles();

        self::assertNull($weak?->get());
    }

    private function assertObservationPayloadIsSafe(): void
    {
        $observation = new ReflectionClass(Observation::class);
        self::assertSame(
            ['type', 'identifier', 'kind', 'outcome', 'errorType', 'reuseDecision'],
            array_map(static fn($property): string => $property->getName(), $observation->getProperties()),
        );

        foreach ($observation->getProperties() as $property) {
            $type = $property->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type);
            self::assertNotContains($type->getName(), [
                'mixed',
                Throwable::class,
                ExecutionScope::class,
                ServiceRegistry::class,
                'Psr\\Container\\ContainerInterface',
            ]);
        }

        foreach (['result', 'throwable', 'exception', 'message', 'trace', 'scope', 'registry', 'container', 'attributes'] as $forbiddenName) {
            self::assertFalse($observation->hasMethod($forbiddenName), Observation::class . ' must not expose ' . $forbiddenName . '().');
        }
    }

    private function frozenRegistry(): ServiceRegistry
    {
        $registry = new ServiceRegistry();
        $registry->register('execution.object', ServiceLifetime::Execution, static fn(): object => new \stdClass());
        $registry->freeze();

        return $registry;
    }

    /**
     * @param callable(): void $callback
     */
    private function participant(callable $callback): ResetParticipant
    {
        return new class ($callback) implements ResetParticipant {
            private \Closure $callback;

            /**
             * @param callable(): void $callback
             */
            public function __construct(callable $callback)
            {
                $this->callback = \Closure::fromCallable($callback);
            }

            public function reset(): void
            {
                ($this->callback)();
            }
        };
    }

    /**
     * @param list<InstrumentationFailure> $failures
     *
     * @return list<ObservationType>
     */
    private function instrumentationFailureTypes(array $failures): array
    {
        return array_map(static fn(InstrumentationFailure $failure): ObservationType => $failure->observationType(), $failures);
    }

    /**
     * @param list<InstrumentationFailure> $failures
     *
     * @return list<string>
     */
    private function instrumentationFailureErrorTypes(array $failures): array
    {
        return array_map(static fn(InstrumentationFailure $failure): string => $failure->errorType(), $failures);
    }
}

class CollectingObservationSink implements ObservationSink
{
    /**
     * @var list<Observation>
     */
    protected array $observations = [];

    public function observe(Observation $observation): void
    {
        $this->observations[] = $observation;
    }

    /**
     * @return list<Observation>
     */
    public function observations(): array
    {
        return $this->observations;
    }

    /**
     * @return list<ObservationType>
     */
    public function types(): array
    {
        return array_map(static fn(Observation $observation): ObservationType => $observation->type(), $this->observations);
    }

    /**
     * @return list<string>
     */
    public function errorTypes(): array
    {
        return array_values(array_filter(
            array_map(static fn(Observation $observation): ?string => $observation->errorType(), $this->observations),
            static fn(?string $errorType): bool => $errorType !== null,
        ));
    }

    /**
     * @return list<string>
     */
    public function identifierValues(): array
    {
        return array_map(static fn(Observation $observation): string => $observation->identifier()->value(), $this->observations);
    }
}

final class ThrowingObservationSink extends CollectingObservationSink
{
    /**
     * @var array<string, true>
     */
    private array $failureTypes;

    /**
     * @param list<ObservationType> $failureTypes
     */
    public function __construct(array $failureTypes, private ?int $remainingFailures = null)
    {
        $this->failureTypes = array_fill_keys(
            array_map(static fn(ObservationType $type): string => $type->name, $failureTypes),
            true,
        );
    }

    public function observe(Observation $observation): void
    {
        parent::observe($observation);

        if (! isset($this->failureTypes[$observation->type()->name])) {
            return;
        }

        if ($this->remainingFailures === 0) {
            return;
        }

        if ($this->remainingFailures !== null) {
            --$this->remainingFailures;
        }

        throw new RuntimeException('secret sink token');
    }
}

class RecordingObservationSink extends CollectingObservationSink
{
    /**
     * @var list<string>
     */
    private array $events;

    /**
     * @param list<string> $events
     */
    public function __construct(private string $name, array &$events)
    {
        $this->events = &$events;
    }

    public function observe(Observation $observation): void
    {
        $this->events[] = $this->name . ':' . $observation->type()->name;

        parent::observe($observation);
    }

    /**
     * @return list<string>
     */
    public function recordedEvents(): array
    {
        return $this->events;
    }
}

final class RecordingThrowingObservationSink extends RecordingObservationSink
{
    /**
     * @var array<string, true>
     */
    private array $failureTypes;

    /**
     * @param list<string> $events
     * @param list<ObservationType> $failureTypes
     */
    public function __construct(string $name, array &$events, array $failureTypes)
    {
        parent::__construct($name, $events);
        $this->failureTypes = array_fill_keys(
            array_map(static fn(ObservationType $type): string => $type->name, $failureTypes),
            true,
        );
    }

    public function observe(Observation $observation): void
    {
        parent::observe($observation);

        if (isset($this->failureTypes[$observation->type()->name])) {
            throw new RuntimeException('secret sink token');
        }
    }
}

final class RecordingExecutionContextAttacher implements ExecutionContextAttacher
{
    /**
     * @var list<string>
     */
    private array $events;

    /**
     * @var list<RecordingExecutionContextAttachment>
     */
    private array $attachments = [];

    /**
     * @param list<string> $events
     */
    public function __construct(
        private string $name,
        array &$events,
        private ?Throwable $detachFailure = null,
    ) {
        $this->events = &$events;
    }

    public function attach(ExecutionContext $context): ExecutionContextAttachment
    {
        $this->events[] = $this->name . ':attach';
        $attachment = new RecordingExecutionContextAttachment($this->name, $this->events, $this->detachFailure);
        $this->attachments[] = $attachment;

        return $attachment;
    }

    public function attachmentCount(): int
    {
        return count($this->attachments);
    }

    /**
     * @return list<string>
     */
    public function recordedEvents(): array
    {
        return $this->events;
    }
}

final class ThrowingExecutionContextAttacher implements ExecutionContextAttacher
{
    /**
     * @var list<string>
     */
    private array $events;

    /**
     * @param list<string> $events
     */
    public function __construct(private string $name, array &$events)
    {
        $this->events = &$events;
    }

    public function attach(ExecutionContext $context): ExecutionContextAttachment
    {
        $this->events[] = $this->name . ':attach';

        throw new RuntimeException('secret attach token');
    }

    /**
     * @return list<string>
     */
    public function recordedEvents(): array
    {
        return $this->events;
    }
}

final class RecordingExecutionContextAttachment implements ExecutionContextAttachment
{
    /**
     * @var list<string>
     */
    private array $events;

    /**
     * @param list<string> $events
     */
    public function __construct(
        private string $name,
        array &$events,
        private ?Throwable $detachFailure = null,
    ) {
        $this->events = &$events;
    }

    public function detach(): void
    {
        $this->events[] = $this->name . ':detach';

        if ($this->detachFailure !== null) {
            throw $this->detachFailure;
        }
    }

    /**
     * @return list<string>
     */
    public function recordedEvents(): array
    {
        return $this->events;
    }
}
