<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Execution;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionResetFailed;
use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionContextValues;
use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Core\Execution\ProcessReuseDecision;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use RuntimeException;
use Throwable;
use WeakReference;

final class ExecutionOrchestratorTest extends TestCase
{
    public function test_execution_identity_kind_and_context_are_local_stable_and_non_ambient(): void
    {
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry());
        $seenContext = null;
        $firstIdentifier = null;
        $secondIdentifier = null;

        $first = $orchestrator->execute(
            ExecutionKind::HttpRequest,
            static function (ExecutionContext $context, ExecutionScope $scope) use (&$seenContext, &$firstIdentifier): string {
                $seenContext = $context;
                $firstIdentifier = $context->identifier();

                self::assertSame($firstIdentifier, $context->identifier());
                self::assertSame(ExecutionKind::HttpRequest, $context->kind());
                self::assertTrue($scope->has('execution.object'));

                return 'handled';
            },
        );
        $second = $orchestrator->execute(
            ExecutionKind::QueueMessage,
            static function (ExecutionContext $context) use (&$secondIdentifier): string {
                $secondIdentifier = $context->identifier();

                return 'queued';
            },
        );

        self::assertInstanceOf(ExecutionContext::class, $seenContext);
        self::assertInstanceOf(ExecutionIdentifier::class, $firstIdentifier);
        self::assertNotSame('', $firstIdentifier->value());
        self::assertSame($firstIdentifier, $first->identifier());
        self::assertSame(ExecutionKind::HttpRequest, $first->kind());
        self::assertSame($secondIdentifier, $second->identifier());
        self::assertSame(ExecutionKind::QueueMessage, $second->kind());
        self::assertNotSame($first->identifier()->value(), $second->identifier()->value());

        foreach ([ExecutionContext::class, ExecutionScope::class, ExecutionIdentifier::class] as $class) {
            foreach (['current', 'currentExecution', 'currentScope', 'setCurrent', 'fromUserInput', 'fromRequest', 'fromRequestLocale'] as $method) {
                self::assertFalse(method_exists($class, $method), $class . ' must not expose ambient or user-input identity API.');
            }
        }

        $identifierApi = new ReflectionClass(ExecutionIdentifier::class);
        self::assertFalse($identifierApi->hasMethod('fromString'));
        self::assertFalse($identifierApi->hasMethod('fromRequest'));

        $kindApi = new ReflectionEnum(ExecutionKind::class);
        self::assertSame(
            ['HttpRequest', 'QueueMessage', 'ScheduledJob', 'CliCommand', 'WorkerTask'],
            array_map(static fn($case): string => $case->getName(), $kindApi->getCases()),
        );
    }

    public function test_execution_context_exposes_explicit_locale_and_timezone_values(): void
    {
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry());
        $values = new ExecutionContextValues('en-NG', 'Africa/Lagos');

        $outcome = $orchestrator->execute(
            ExecutionKind::HttpRequest,
            static function (ExecutionContext $context) use ($values): string {
                self::assertSame($values, $context->values());
                self::assertSame('en-NG', $context->locale());
                self::assertSame('Africa/Lagos', $context->timezone());

                return 'localized';
            },
            $values,
        );

        self::assertTrue($outcome->primarySucceeded());
        self::assertSame('localized', $outcome->primaryResult());
    }

    public function test_execution_without_context_values_exposes_explicit_absence(): void
    {
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry());

        $orchestrator->execute(
            ExecutionKind::CliCommand,
            static function (ExecutionContext $context): string {
                self::assertNull($context->locale());
                self::assertNull($context->timezone());
                self::assertNull($context->values()->locale());
                self::assertNull($context->values()->timezone());

                return 'absent';
            },
        );
    }

    public function test_context_values_are_isolated_across_sequential_executions_and_failures(): void
    {
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry());
        $seen = [];
        $failure = new RuntimeException('first failed');

        $first = $orchestrator->execute(
            ExecutionKind::QueueMessage,
            static function (ExecutionContext $context) use (&$seen, $failure): never {
                $seen[] = [$context->locale(), $context->timezone()];

                throw $failure;
            },
            new ExecutionContextValues('en-NG', 'Africa/Lagos'),
        );
        $second = $orchestrator->execute(
            ExecutionKind::QueueMessage,
            static function (ExecutionContext $context) use (&$seen): string {
                $seen[] = [$context->locale(), $context->timezone()];

                return 'second';
            },
            new ExecutionContextValues('fr-FR', 'Europe/Paris'),
        );
        $third = $orchestrator->execute(
            ExecutionKind::QueueMessage,
            static function (ExecutionContext $context) use (&$seen): string {
                $seen[] = [$context->locale(), $context->timezone()];

                return 'third';
            },
        );

        self::assertSame($failure, $first->primaryThrowable());
        self::assertTrue($second->primarySucceeded());
        self::assertTrue($third->primarySucceeded());
        self::assertSame(
            [
                ['en-NG', 'Africa/Lagos'],
                ['fr-FR', 'Europe/Paris'],
                [null, null],
            ],
            $seen,
        );
    }

    public function test_all_execution_kinds_carry_context_values(): void
    {
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry());

        foreach (ExecutionKind::cases() as $kind) {
            $orchestrator->execute(
                $kind,
                static function (ExecutionContext $context) use ($kind): string {
                    self::assertSame($kind, $context->kind());
                    self::assertSame('en-GB', $context->locale());
                    self::assertSame('Europe/London', $context->timezone());

                    return $kind->name;
                },
                new ExecutionContextValues('en-GB', 'Europe/London'),
            );
        }
    }

    public function test_execution_context_values_preserve_process_global_locale_and_timezone_defaults(): void
    {
        $defaultTimezone = date_default_timezone_get();
        $defaultLocale = setlocale(LC_ALL, '0');
        $defaultIntlLocale = class_exists(\Locale::class) ? \Locale::getDefault() : null;

        try {
            (new ExecutionOrchestrator($this->frozenRegistry()))->execute(
                ExecutionKind::HttpRequest,
                static function (ExecutionContext $context): string {
                    self::assertSame('fr-FR', $context->locale());
                    self::assertSame('Europe/Paris', $context->timezone());

                    return 'ok';
                },
                new ExecutionContextValues('fr-FR', 'Europe/Paris'),
            );

            self::assertSame($defaultTimezone, date_default_timezone_get());
            self::assertSame($defaultLocale, setlocale(LC_ALL, '0'));

            if ($defaultIntlLocale !== null) {
                self::assertSame($defaultIntlLocale, \Locale::getDefault());
            }
        } finally {
            self::assertSame($defaultTimezone, date_default_timezone_get());
        }
    }

    public function test_execution_context_values_validate_locale_and_timezone_inputs(): void
    {
        self::assertSame('en-NG', (new ExecutionContextValues('en-NG', 'UTC'))->locale());
        self::assertSame('Africa/Lagos', (new ExecutionContextValues('en_US_POSIX', 'Africa/Lagos'))->timezone());
        self::assertSame('sr-Latn-RS', (new ExecutionContextValues('sr-Latn-RS', 'Europe/London'))->locale());
        self::assertNull((new ExecutionContextValues())->locale());
        self::assertNull((new ExecutionContextValues())->timezone());

        foreach ([
            ['', 'UTC'],
            ['   ', 'UTC'],
            [' en-NG', 'UTC'],
            ['en-NG ', 'UTC'],
            ["en-\nNG", 'UTC'],
            [str_repeat('a', 65), 'UTC'],
            ['en@NG', 'UTC'],
            ['en-NG', ''],
            ['en-NG', ' Africa/Lagos'],
            ['en-NG', 'Africa/Lagos '],
            ['en-NG', str_repeat('A', 256)],
            ['en-NG', 'Mars/Base'],
        ] as [$locale, $timezone]) {
            $rejected = false;

            try {
                new ExecutionContextValues($locale, $timezone);
            } catch (InvalidArgumentException) {
                $rejected = true;
            }

            self::assertTrue($rejected, 'Invalid execution context values should be rejected.');
        }
    }

    public function test_successful_execution_preserves_result_closes_scope_and_allows_reuse(): void
    {
        $calls = 0;
        $closed = false;
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry());

        $outcome = $orchestrator->execute(
            ExecutionKind::WorkerTask,
            function (ExecutionContext $context, ExecutionScope $scope) use (&$calls, &$closed): string {
                ++$calls;
                $scope->registerResetParticipant('close-marker', $this->participant(static function () use (&$closed): void {
                    $closed = true;
                }));

                return 'result:' . $context->identifier()->value();
            },
        );

        self::assertSame(1, $calls);
        self::assertTrue($closed);
        self::assertTrue($outcome->primarySucceeded());
        self::assertFalse($outcome->primaryFailed());
        self::assertStringStartsWith('result:', $outcome->primaryResult());
        self::assertNull($outcome->primaryThrowable());
        self::assertFalse($outcome->cleanupFailed());
        self::assertNull($outcome->cleanupThrowable());
        self::assertSame(ProcessReuseDecision::Reusable, $outcome->reuseDecision());
        self::assertTrue($outcome->isReusable());
        self::assertFalse($outcome->requiresQuarantine());
    }

    public function test_handler_failure_remains_primary_scope_still_closes_and_clean_cleanup_stays_reusable(): void
    {
        $handlerFailure = new RuntimeException('handler failed');
        $closed = false;
        $calls = 0;
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry());

        $outcome = $orchestrator->execute(
            ExecutionKind::CliCommand,
            function (ExecutionContext $context, ExecutionScope $scope) use (&$calls, &$closed, $handlerFailure): never {
                ++$calls;
                $scope->registerResetParticipant('close-marker', $this->participant(static function () use (&$closed): void {
                    $closed = true;
                }));

                throw $handlerFailure;
            },
        );

        self::assertSame(1, $calls);
        self::assertTrue($closed);
        self::assertFalse($outcome->primarySucceeded());
        self::assertTrue($outcome->primaryFailed());
        self::assertSame($handlerFailure, $outcome->primaryThrowable());
        self::assertFalse($outcome->cleanupFailed());
        self::assertSame(ProcessReuseDecision::Reusable, $outcome->reuseDecision());
        self::assertTrue($outcome->isReusable());
        self::assertFalse($outcome->requiresQuarantine());
    }

    public function test_cleanup_failure_after_success_preserves_primary_result_and_requires_quarantine(): void
    {
        $resetFailure = new RuntimeException('reset failed');
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry());

        $outcome = $orchestrator->execute(
            ExecutionKind::ScheduledJob,
            function (ExecutionContext $context, ExecutionScope $scope) use ($resetFailure): string {
                $scope->registerResetParticipant('broken', $this->participant(static function () use ($resetFailure): never {
                    throw $resetFailure;
                }));

                return 'successful primary';
            },
        );

        self::assertTrue($outcome->primarySucceeded());
        self::assertSame('successful primary', $outcome->primaryResult());
        self::assertNull($outcome->primaryThrowable());
        self::assertTrue($outcome->cleanupFailed());
        self::assertInstanceOf(ExecutionResetFailed::class, $outcome->cleanupThrowable());
        self::assertSame([$resetFailure], $outcome->cleanupThrowable()->failures());
        self::assertSame(ProcessReuseDecision::QuarantineRequired, $outcome->reuseDecision());
        self::assertFalse($outcome->isReusable());
        self::assertTrue($outcome->requiresQuarantine());
    }

    public function test_cleanup_failure_after_handler_failure_preserves_both_failures_and_requires_quarantine(): void
    {
        $handlerFailure = new RuntimeException('handler failed');
        $resetFailure = new RuntimeException('reset failed');
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry());

        $outcome = $orchestrator->execute(
            ExecutionKind::HttpRequest,
            function (ExecutionContext $context, ExecutionScope $scope) use ($handlerFailure, $resetFailure): never {
                $scope->registerResetParticipant('broken', $this->participant(static function () use ($resetFailure): never {
                    throw $resetFailure;
                }));

                throw $handlerFailure;
            },
        );

        self::assertTrue($outcome->primaryFailed());
        self::assertSame($handlerFailure, $outcome->primaryThrowable());
        self::assertTrue($outcome->cleanupFailed());
        self::assertInstanceOf(ExecutionResetFailed::class, $outcome->cleanupThrowable());
        self::assertSame([$resetFailure], $outcome->cleanupThrowable()->failures());
        self::assertNotSame($handlerFailure, $outcome->cleanupThrowable());
        self::assertSame(ProcessReuseDecision::QuarantineRequired, $outcome->reuseDecision());
    }

    public function test_quarantined_orchestrator_refuses_later_execution_without_retrying_work(): void
    {
        $resetFailure = new RuntimeException('reset failed');
        $orchestrator = new ExecutionOrchestrator($this->frozenRegistry());
        $laterCalls = 0;

        $first = $orchestrator->execute(
            ExecutionKind::WorkerTask,
            function (ExecutionContext $context, ExecutionScope $scope) use ($resetFailure): string {
                $scope->registerResetParticipant('broken', $this->participant(static function () use ($resetFailure): never {
                    throw $resetFailure;
                }));

                return 'first';
            },
        );

        self::assertTrue($first->requiresQuarantine());

        try {
            $orchestrator->execute(
                ExecutionKind::WorkerTask,
                static function () use (&$laterCalls): string {
                    ++$laterCalls;

                    return 'later';
                },
            );
            self::fail('A quarantined orchestrator must refuse later executions.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ExecutionStartFailed::class, $exception);
        }

        self::assertSame(0, $laterCalls);
    }

    public function test_sequential_clean_executions_are_isolated_even_after_handler_failure(): void
    {
        $factoryCalls = 0;
        $firstService = null;
        $secondService = null;
        $registry = new ServiceRegistry();
        $registry->register('execution.object', ServiceLifetime::Execution, static function () use (&$factoryCalls): object {
            ++$factoryCalls;

            return new \stdClass();
        });
        $registry->freeze();
        $orchestrator = new ExecutionOrchestrator($registry);

        $firstFailure = new RuntimeException('first failed');
        $first = $orchestrator->execute(
            ExecutionKind::QueueMessage,
            static function (ExecutionContext $context, ExecutionScope $scope) use (&$firstService, $firstFailure): never {
                $firstService = $scope->get('execution.object');

                throw $firstFailure;
            },
        );
        $second = $orchestrator->execute(
            ExecutionKind::QueueMessage,
            static function (ExecutionContext $context, ExecutionScope $scope) use (&$secondService): string {
                $secondService = $scope->get('execution.object');

                return 'second ok';
            },
        );

        self::assertSame($firstFailure, $first->primaryThrowable());
        self::assertTrue($first->isReusable());
        self::assertTrue($second->primarySucceeded());
        self::assertSame('second ok', $second->primaryResult());
        self::assertSame(2, $factoryCalls);
        self::assertNotSame($firstService, $secondService);
        self::assertNotSame($first->identifier()->value(), $second->identifier()->value());
    }

    public function test_outcome_and_context_do_not_retain_scope_or_execution_services_after_cleanup(): void
    {
        $weak = null;
        $registry = new ServiceRegistry();
        $registry->register('execution.object', ServiceLifetime::Execution, static function () use (&$weak): object {
            $service = new \stdClass();
            $weak = WeakReference::create($service);

            return $service;
        });
        $registry->freeze();

        $outcome = (new ExecutionOrchestrator($registry))->execute(
            ExecutionKind::HttpRequest,
            static function (ExecutionContext $context, ExecutionScope $scope): string {
                $scope->get('execution.object');

                return 'done';
            },
        );

        gc_collect_cycles();

        self::assertNull($weak?->get());
    }

    public function test_cleanup_failure_path_releases_scope_and_services_while_recording_cleanup_failure(): void
    {
        $weak = null;
        $registry = new ServiceRegistry();
        $registry->register('execution.object', ServiceLifetime::Execution, static function () use (&$weak): object {
            $service = new \stdClass();
            $weak = WeakReference::create($service);

            return $service;
        });
        $registry->freeze();
        $resetFailure = new RuntimeException('reset failed');

        $outcome = (new ExecutionOrchestrator($registry))->execute(
            ExecutionKind::WorkerTask,
            function (ExecutionContext $context, ExecutionScope $scope) use ($resetFailure): string {
                $scope->get('execution.object');
                $scope->registerResetParticipant('broken', $this->participant(static function () use ($resetFailure): never {
                    throw $resetFailure;
                }));

                return 'done';
            },
        );

        gc_collect_cycles();

        self::assertTrue($outcome->cleanupFailed());
        self::assertNull($weak?->get());
    }

    public function test_start_failures_are_not_reported_as_completed_handler_outcomes(): void
    {
        $orchestrator = new ExecutionOrchestrator(new ServiceRegistry());
        $calls = 0;

        try {
            $orchestrator->execute(
                ExecutionKind::HttpRequest,
                static function () use (&$calls): string {
                    ++$calls;

                    return 'should not run';
                },
            );
            self::fail('Execution without a usable frozen registry should fail before handling.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ExecutionStartFailed::class, $exception);
        }

        self::assertSame(0, $calls);
    }

    public function test_terminal_outcome_state_api_prevents_invalid_public_construction(): void
    {
        $outcomeApi = new ReflectionClass(ExecutionOutcome::class);
        $constructor = $outcomeApi->getConstructor();

        self::assertNotNull($constructor);
        self::assertFalse($constructor->isPublic());

        foreach (['reusableAfterCleanupFailure', 'quarantineWithoutReason', 'retry', 'statusCode', 'exitCode', 'ack', 'nack'] as $method) {
            self::assertFalse($outcomeApi->hasMethod($method), ExecutionOutcome::class . ' must not expose ' . $method . '().');
        }

        self::assertFalse(ProcessReuseDecision::Reusable->requiresQuarantine());
        self::assertTrue(ProcessReuseDecision::Reusable->allowsReuse());
        self::assertTrue(ProcessReuseDecision::QuarantineRequired->requiresQuarantine());
        self::assertFalse(ProcessReuseDecision::QuarantineRequired->allowsReuse());

        $this->expectException(LogicException::class);

        (new ExecutionOrchestrator($this->frozenRegistry()))
            ->execute(ExecutionKind::HttpRequest, static fn(): string => 'ok')
            ->primaryThrowableOrFail();
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
}
