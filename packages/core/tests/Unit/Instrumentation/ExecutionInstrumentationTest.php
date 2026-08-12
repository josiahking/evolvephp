<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Instrumentation;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionResetFailed;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Core\Execution\ProcessReuseDecision;
use Evolve\Core\Instrumentation\InstrumentationFailure;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Core\Instrumentation\ObservationSink;
use Evolve\Core\Instrumentation\ObservationType;
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
