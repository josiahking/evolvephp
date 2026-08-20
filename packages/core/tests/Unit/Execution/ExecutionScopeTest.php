<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Execution;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionResetFailed;
use Evolve\Core\Exception\ExecutionScopeClosed;
use Evolve\Core\Exception\ExecutionScopeUnavailable;
use Evolve\Core\Exception\InvalidResetParticipant;
use Evolve\Core\Exception\ServiceResolutionFailed;
use Evolve\Core\Execution\ExecutionScope;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use WeakReference;

final class ExecutionScopeTest extends TestCase
{
    public function test_public_boundary_is_psr11_scope_without_execution_identity_api(): void
    {
        $scope = $this->scopeFrom(new ServiceRegistry());

        self::assertContains(ExecutionScope::class, class_implements($scope));
        self::assertContains(ContainerInterface::class, class_implements($scope));

        foreach (['identifier', 'state', 'isClosed', 'resolver', 'container', 'begin', 'execute', 'run', 'outcome', 'quarantine'] as $method) {
            self::assertFalse(method_exists($scope, $method), ExecutionScope::class . ' must not expose ' . $method . '().');
        }
    }

    public function test_application_execution_and_transient_lifetimes_inside_scopes(): void
    {
        $applicationCalls = 0;
        $executionCalls = 0;
        $transientCalls = 0;
        $registry = new ServiceRegistry();
        $registry->register('application.object', ServiceLifetime::Application, static function () use (&$applicationCalls): object {
            ++$applicationCalls;

            return new \stdClass();
        });
        $registry->register('application.null', ServiceLifetime::Application, static fn(): null => null);
        $registry->register('application.scalar', ServiceLifetime::Application, static fn(): string => 'application');
        $registry->register('application.array', ServiceLifetime::Application, static fn(): array => ['application']);
        $registry->register('execution.object', ServiceLifetime::Execution, static function () use (&$executionCalls): object {
            ++$executionCalls;

            return new \stdClass();
        });
        $registry->register('execution.null', ServiceLifetime::Execution, static function () use (&$executionCalls): null {
            ++$executionCalls;

            return null;
        });
        $registry->register('execution.scalar', ServiceLifetime::Execution, static fn(): string => 'execution');
        $registry->register('execution.array', ServiceLifetime::Execution, static fn(): array => ['execution']);
        $registry->register('transient', ServiceLifetime::Transient, static function () use (&$transientCalls): object {
            ++$transientCalls;

            return new \stdClass();
        });

        $root = $registry->freeze();
        $firstScope = $registry->createExecutionScope();
        $secondScope = $registry->createExecutionScope();

        self::assertSame($firstScope->get('application.object'), $firstScope->get('application.object'));
        self::assertSame($firstScope->get('application.object'), $secondScope->get('application.object'));
        self::assertNull($firstScope->get('application.null'));
        self::assertSame('application', $firstScope->get('application.scalar'));
        self::assertSame(['application'], $firstScope->get('application.array'));
        self::assertSame(1, $applicationCalls);

        self::assertSame($firstScope->get('execution.object'), $firstScope->get('execution.object'));
        self::assertNotSame($firstScope->get('execution.object'), $secondScope->get('execution.object'));
        self::assertNull($firstScope->get('execution.null'));
        self::assertNull($firstScope->get('execution.null'));
        self::assertSame('execution', $firstScope->get('execution.scalar'));
        self::assertSame(['execution'], $firstScope->get('execution.array'));
        self::assertSame(3, $executionCalls);

        self::assertNotSame($firstScope->get('transient'), $firstScope->get('transient'));
        self::assertNotSame($root->get('transient'), $root->get('transient'));
        self::assertSame(4, $transientCalls);
    }

    public function test_factories_receive_lifetime_appropriate_resolver_context(): void
    {
        $seen = [];
        $registry = new ServiceRegistry();
        $registry->register('application', ServiceLifetime::Application, static function (ContainerInterface $container) use (&$seen): string {
            $seen['application'] = $container;

            return 'application';
        });
        $registry->register('execution', ServiceLifetime::Execution, static function (ContainerInterface $container) use (&$seen): string {
            $seen['execution'] = $container;

            return 'execution';
        });
        $registry->register('transient', ServiceLifetime::Transient, static function (ContainerInterface $container) use (&$seen): string {
            $seen[] = $container;

            return 'transient';
        });

        $root = $registry->freeze();
        $scope = $registry->createExecutionScope();

        $root->get('transient');
        $scope->get('application');
        $scope->get('execution');
        $scope->get('transient');

        self::assertSame($root, $seen['application']);
        self::assertSame($scope, $seen['execution']);
        self::assertSame($root, $seen[0]);
        self::assertSame($scope, $seen[1]);
    }

    public function test_captive_dependency_rules_follow_resolver_context(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('application-needs-execution', ServiceLifetime::Application, static fn(ContainerInterface $container): mixed => $container->get('execution'));
        $registry->register('execution', ServiceLifetime::Execution, static fn(): string => 'execution');
        $registry->register('execution-needs-application', ServiceLifetime::Execution, static fn(ContainerInterface $container): string => 'uses:' . $container->get('application'));
        $registry->register('execution-needs-transient', ServiceLifetime::Execution, static fn(ContainerInterface $container): string => 'uses:' . $container->get('transient'));
        $registry->register('application', ServiceLifetime::Application, static fn(): string => 'application');
        $registry->register('transient', ServiceLifetime::Transient, static fn(ContainerInterface $container): mixed => $container->get('execution'));

        $root = $registry->freeze();
        $scope = $registry->createExecutionScope();

        $this->assertThrows(ExecutionScopeUnavailable::class, static fn(): mixed => $scope->get('application-needs-execution'));
        self::assertSame('uses:application', $scope->get('execution-needs-application'));
        self::assertSame('uses:execution', $scope->get('execution-needs-transient'));
        self::assertSame('execution', $scope->get('transient'));
        $this->assertThrows(ExecutionScopeUnavailable::class, static fn(): mixed => $root->get('transient'));
    }

    public function test_psr_presence_and_unknown_service_behavior_are_preserved(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('application', ServiceLifetime::Application, static fn(): string => 'application');
        $registry->register('execution', ServiceLifetime::Execution, static fn(): string => 'execution');
        $registry->register('transient', ServiceLifetime::Transient, static fn(): string => 'transient');

        $scope = $this->scopeFrom($registry);

        self::assertTrue($scope->has('application'));
        self::assertTrue($scope->has('execution'));
        self::assertTrue($scope->has('transient'));
        self::assertFalse($scope->has('missing'));
        $this->assertThrows(NotFoundExceptionInterface::class, static fn(): mixed => $scope->get('missing'));
    }

    public function test_scoped_circular_dependencies_fail_and_cleanup_resolution_state(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('self-cycle', ServiceLifetime::Execution, static fn(ContainerInterface $container): mixed => $container->get('self-cycle'));
        $registry->register('a', ServiceLifetime::Execution, static fn(ContainerInterface $container): mixed => $container->get('b'));
        $registry->register('b', ServiceLifetime::Execution, static fn(ContainerInterface $container): mixed => $container->get('a'));
        $registry->register('c', ServiceLifetime::Execution, static fn(ContainerInterface $container): mixed => $container->get('transient-cycle'));
        $registry->register('transient-cycle', ServiceLifetime::Transient, static fn(ContainerInterface $container): mixed => $container->get('c'));
        $registry->register('healthy', ServiceLifetime::Execution, static fn(): string => 'healthy');

        $scope = $this->scopeFrom($registry);

        $this->assertThrows(ServiceResolutionFailed::class, static fn(): mixed => $scope->get('self-cycle'));
        $this->assertThrows(ServiceResolutionFailed::class, static fn(): mixed => $scope->get('a'));
        $this->assertThrows(ServiceResolutionFailed::class, static fn(): mixed => $scope->get('c'));
        self::assertSame('healthy', $scope->get('healthy'));
    }

    public function test_close_is_terminal_idempotent_and_side_effect_free_for_has(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('execution', ServiceLifetime::Execution, static fn(): string => 'execution');

        $scope = $this->scopeFrom($registry);
        $scope->close();
        $scope->close();

        self::assertTrue($scope->has('execution'));
        $this->assertThrows(ExecutionScopeClosed::class, static fn(): mixed => $scope->get('execution'));
        $this->assertThrows(ExecutionScopeClosed::class, function () use ($scope): void {
            $scope->registerResetParticipant('later', $this->participant(static function (): void {}));
        });

        self::assertInstanceOf(ContainerExceptionInterface::class, $this->catchThrowable(static fn(): mixed => $scope->get('execution')));
        self::assertSame('execution', $registry->createExecutionScope()->get('execution'));
    }

    public function test_reset_registration_is_explicit_opaque_and_runs_in_reverse_registration_order(): void
    {
        $events = new class {
            /**
             * @var list<string>
             */
            public array $values = [];
        };
        $scope = $this->scopeFrom(new ServiceRegistry());

        $scope->registerResetParticipant(' ', $this->participant(static function () use ($events): void {
            $events->values[] = 'space';
        }));
        $scope->registerResetParticipant('A', $this->participant(static function () use ($events): void {
            $events->values[] = 'A';
        }));
        $scope->registerResetParticipant('a', $this->participant(static function () use ($events): void {
            $events->values[] = 'a';
        }));

        $this->assertThrows(InvalidResetParticipant::class, function () use ($scope): void {
            $scope->registerResetParticipant('', $this->participant(static function (): void {}));
        });
        $this->assertThrows(InvalidResetParticipant::class, function () use ($scope): void {
            $scope->registerResetParticipant('A', $this->participant(static function (): void {}));
        });

        self::assertSame([], $events->values);

        $scope->close();

        self::assertSame(['a', 'A', 'space'], $events->values);
    }

    public function test_reset_failures_are_best_effort_aggregated_and_do_not_retry(): void
    {
        $events = new class {
            /**
             * @var list<string>
             */
            public array $values = [];
        };
        $firstFailure = new RuntimeException('first');
        $secondFailure = new RuntimeException('second');
        $scope = $this->scopeFrom(new ServiceRegistry());

        $scope->registerResetParticipant('a', $this->participant(static function () use ($events): void {
            $events->values[] = 'a';
        }));
        $scope->registerResetParticipant('b', $this->participant(static function () use ($events, $secondFailure): never {
            $events->values[] = 'b';
            throw $secondFailure;
        }));
        $scope->registerResetParticipant('c', $this->participant(static function () use ($events, $firstFailure): never {
            $events->values[] = 'c';
            throw $firstFailure;
        }));

        try {
            $scope->close();
            self::fail('Reset failures should be aggregated.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ExecutionResetFailed::class, $exception);
            self::assertSame([$firstFailure, $secondFailure], $exception->failures());
            self::assertSame($firstFailure, $exception->getPrevious());
        }

        self::assertSame(['c', 'b', 'a'], $events->values);
        $scope->close();
        self::assertSame(['c', 'b', 'a'], $events->values);
        $this->assertThrows(ExecutionScopeClosed::class, static fn(): mixed => $scope->get('missing'));
    }

    public function test_reentrant_close_does_not_recurse(): void
    {
        $events = new class {
            /**
             * @var list<string>
             */
            public array $values = [];
        };
        $scope = $this->scopeFrom(new ServiceRegistry());
        $scope->registerResetParticipant('reentrant', $this->participant(static function () use ($events, $scope): void {
            $events->values[] = 'reset';
            $scope->close();
        }));

        $scope->close();

        self::assertSame(['reset'], $events->values);
    }

    public function test_close_releases_execution_and_reset_participant_references_even_when_reset_fails(): void
    {
        $firstWeak = null;
        $secondWeak = null;
        $failure = new RuntimeException('reset failed');
        $registry = new ServiceRegistry();
        $registry->register('execution', ServiceLifetime::Execution, static function () use (&$firstWeak): object {
            $service = new \stdClass();
            $firstWeak = WeakReference::create($service);

            return $service;
        });

        $scope = $this->scopeFrom($registry);
        $scope->get('execution');
        $participant = $this->participant(static function () use ($failure): never {
            throw $failure;
        });
        $secondWeak = WeakReference::create($participant);
        $scope->registerResetParticipant('participant', $participant);
        unset($participant);

        $this->assertThrows(ExecutionResetFailed::class, static function () use ($scope): void {
            $scope->close();
        });

        gc_collect_cycles();

        self::assertNull($firstWeak?->get());
        self::assertNull($secondWeak->get());
    }

    public function test_close_releases_references_without_automatic_disposal(): void
    {
        $disposed = false;
        $weak = null;
        $registry = new ServiceRegistry();
        $registry->register('execution', ServiceLifetime::Execution, static function () use (&$disposed, &$weak): object {
            $service = new class (static function () use (&$disposed): void {
                $disposed = true;
            }) {
                private \Closure $markDisposed;

                /**
                 * @param callable(): void $markDisposed
                 */
                public function __construct(callable $markDisposed)
                {
                    $this->markDisposed = \Closure::fromCallable($markDisposed);
                }

                public function reset(): void
                {
                    ($this->markDisposed)();
                }
            };
            $weak = WeakReference::create($service);

            return $service;
        });

        $scope = $this->scopeFrom($registry);
        $scope->get('execution');
        $scope->close();

        gc_collect_cycles();

        self::assertFalse($disposed);
        self::assertNull($weak?->get());
    }

    public function test_numeric_looking_ids_use_prefixed_execution_instance_and_resolving_keys(): void
    {
        $seenResolvingKeys = [];
        $registry = new ServiceRegistry();
        $registry->register('1', ServiceLifetime::Execution, function (ContainerInterface $container) use (&$seenResolvingKeys): string {
            $seenResolvingKeys = array_keys($this->propertyValue($container, 'resolving'));

            return '1';
        });
        $registry->register('01', ServiceLifetime::Execution, static fn(): string => '01');
        $registry->register('-1', ServiceLifetime::Execution, static fn(): string => '-1');
        $registry->register('+1', ServiceLifetime::Execution, static fn(): string => '+1');
        $registry->register('0', ServiceLifetime::Execution, static fn(): string => '0');

        $scope = $this->scopeFrom($registry);

        self::assertSame('1', $scope->get('1'));
        self::assertSame(['service:1'], $seenResolvingKeys);
        self::assertSame(['service:1'], array_keys($this->propertyValue($scope, 'instances')));
        self::assertSame('01', $scope->get('01'));
        self::assertSame('-1', $scope->get('-1'));
        self::assertSame('+1', $scope->get('+1'));
        self::assertSame('0', $scope->get('0'));
    }

    private function scopeFrom(ServiceRegistry $registry): ExecutionScope
    {
        $registry->freeze();

        return $registry->createExecutionScope();
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
     * @param class-string $expected
     * @param callable(): mixed $operation
     */
    private function assertThrows(string $expected, callable $operation): void
    {
        $exception = $this->catchThrowable($operation);

        self::assertInstanceOf($expected, $exception);
    }

    /**
     * @param callable(): mixed $operation
     */
    private function catchThrowable(callable $operation): Throwable
    {
        try {
            $operation();
            self::fail('Operation should throw.');
        } catch (Throwable $exception) {
            return $exception;
        }
    }

    private function propertyValue(object $object, string $property): mixed
    {
        $reflection = new ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}
