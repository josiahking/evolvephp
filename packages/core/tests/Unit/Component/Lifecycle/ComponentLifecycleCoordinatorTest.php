<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Component\Lifecycle;

use Closure;
use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\ComponentDependency;
use Evolve\Contracts\Component\ComponentDependencyKind;
use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentGraphRelations;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Core\Component\Lifecycle\ComponentLifecycleBinding;
use Evolve\Core\Component\Lifecycle\ComponentLifecycleCoordinator;
use Evolve\Core\Component\ResolvedComponentGraph;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ComponentServiceRegistrationFailed;
use Evolve\Core\Exception\ComponentShutdownFailed;
use Evolve\Core\Exception\ComponentStartupFailed;
use Evolve\Core\Exception\InvalidLifecycleTransition;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class ComponentLifecycleCoordinatorTest extends TestCase
{
    public function test_register_boot_and_ready_use_dependency_first_resolved_order(): void
    {
        self::assertTrue(class_exists(ComponentLifecycleCoordinator::class), ComponentLifecycleCoordinator::class . ' should exist.');

        $dependency = $this->declaration('dependency');
        $dependent = $this->declaration('dependent', ['dependency']);
        $calls = [];
        $registry = new ServiceRegistry();
        $coordinator = $this->coordinator([
            [$dependency, $this->entryPoint('dependency', $calls)],
            [$dependent, $this->entryPoint('dependent', $calls)],
        ]);

        $coordinator->register($registry);
        $services = $registry->freeze();
        $coordinator->boot($services);
        $coordinator->ready();

        self::assertSame([
            'dependency:register',
            'dependent:register',
            'dependency:boot',
            'dependent:boot',
            'dependency:ready',
            'dependent:ready',
        ], $calls);
    }

    public function test_normal_shutdown_uses_reverse_successful_boot_order(): void
    {
        $dependency = $this->declaration('dependency');
        $dependent = $this->declaration('dependent', ['dependency']);
        $calls = [];
        $coordinator = $this->registeredReadyCoordinator([
            [$dependency, $this->entryPoint('dependency', $calls)],
            [$dependent, $this->entryPoint('dependent', $calls)],
        ]);

        $coordinator->shutdown();

        self::assertSame([
            'dependency:register',
            'dependent:register',
            'dependency:boot',
            'dependent:boot',
            'dependency:ready',
            'dependent:ready',
            'dependent:shutdown',
            'dependency:shutdown',
        ], $calls);
    }

    public function test_boot_receives_frozen_resolver_without_eager_service_resolution(): void
    {
        $component = $this->declaration('component');
        $calls = [];
        $constructed = 0;
        $registry = new ServiceRegistry();
        $entryPoint = $this->entryPoint('component', $calls, boot: static function (ComponentBootContext $context) use (&$calls, &$constructed): void {
            $calls[] = 'boot:has:' . ($context->services()->has('lazy.service') ? 'yes' : 'no');
            $calls[] = 'boot:constructed:' . $constructed;
        });

        $coordinator = $this->coordinator([[$component, $entryPoint]]);
        $coordinator->register($registry);
        $registry->register('lazy.service', ServiceLifetime::Application, static function () use (&$constructed): object {
            ++$constructed;

            return new \stdClass();
        });
        $services = $registry->freeze();
        $coordinator->boot($services);

        self::assertSame(['component:register', 'component:boot', 'boot:has:yes', 'boot:constructed:0'], $calls);
        self::assertSame(0, $constructed);
    }

    public function test_boot_context_failure_cleanup_is_lifo_failure_only_and_closed_after_use(): void
    {
        $component = $this->declaration('component');
        $calls = [];
        $bootFailure = new RuntimeException('boot failed');
        $cleanupFailure = new RuntimeException('cleanup failed');
        $retainedContext = null;
        $coordinator = $this->coordinator([
            [$component, $this->entryPoint('component', $calls, boot: static function (ComponentBootContext $context) use (&$calls, &$retainedContext, $bootFailure, $cleanupFailure): void {
                $retainedContext = $context;
                $context->deferFailureCleanup(static function () use (&$calls): void {
                    $calls[] = 'cleanup:first';
                });
                $context->deferFailureCleanup(static function () use (&$calls, $cleanupFailure): never {
                    $calls[] = 'cleanup:second';

                    throw $cleanupFailure;
                });

                throw $bootFailure;
            })],
        ]);

        try {
            $this->registerAndBoot($coordinator);
            self::fail('Component boot should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ComponentStartupFailed::class, $exception);
            self::assertSame($bootFailure, $exception->getPrevious());
            self::assertSame('boot', $exception->phase());
            self::assertSame('component', $exception->component()->value());
            self::assertCount(1, $exception->cleanupFailures());
            self::assertSame('component', $exception->cleanupFailures()[0]->component()->value());
            self::assertSame($cleanupFailure, $exception->cleanupFailures()[0]->throwable());
        }

        self::assertSame(['component:register', 'component:boot', 'cleanup:second', 'cleanup:first'], $calls);
        self::assertInstanceOf(ComponentBootContext::class, $retainedContext);
        $this->assertThrows(LogicException::class, static function () use ($retainedContext): void {
            $retainedContext->services();
        });
        $this->assertThrows(LogicException::class, static function () use ($retainedContext): void {
            $retainedContext->deferFailureCleanup(static function (): void {});
        });

        $successCalls = [];
        $successfulDeclaration = $this->declaration('success');
        $successful = $this->coordinator([
            [$successfulDeclaration, $this->entryPoint('success', $successCalls, boot: static function (ComponentBootContext $context) use (&$successCalls): void {
                $context->deferFailureCleanup(static function () use (&$successCalls): void {
                    $successCalls[] = 'cleanup:should-not-run';
                });
            })],
        ]);
        $this->registerBootReady($successful);
        $successful->shutdown();

        self::assertSame(['success:register', 'success:boot', 'success:ready', 'success:shutdown'], $successCalls);
    }

    public function test_boot_failure_skips_failed_component_shutdown_and_cleans_previous_boots_best_effort(): void
    {
        $alpha = $this->declaration('alpha');
        $beta = $this->declaration('beta', ['alpha']);
        $calls = [];
        $bootFailure = new RuntimeException('beta boot failed');
        $alphaShutdownFailure = new RuntimeException('alpha shutdown failed');
        $betaCleanupFailure = new RuntimeException('beta cleanup failed');
        $coordinator = $this->coordinator([
            [$alpha, $this->entryPoint('alpha', $calls, shutdown: static function () use ($alphaShutdownFailure): never {
                throw $alphaShutdownFailure;
            })],
            [$beta, $this->entryPoint('beta', $calls, boot: static function (ComponentBootContext $context) use ($bootFailure, $betaCleanupFailure): never {
                $context->deferFailureCleanup(static function () use ($betaCleanupFailure): never {
                    throw $betaCleanupFailure;
                });

                throw $bootFailure;
            })],
        ]);

        try {
            $this->registerAndBoot($coordinator);
            self::fail('Component boot should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ComponentStartupFailed::class, $exception);
            self::assertSame($bootFailure, $exception->getPrevious());
            self::assertSame('boot', $exception->phase());
            self::assertSame('beta', $exception->component()->value());
            self::assertCount(2, $exception->cleanupFailures());
            self::assertSame(['beta', 'alpha'], array_map(static fn($failure): string => $failure->component()->value(), $exception->cleanupFailures()));
            self::assertSame([$betaCleanupFailure, $alphaShutdownFailure], array_map(static fn($failure): Throwable => $failure->throwable(), $exception->cleanupFailures()));
        }

        self::assertSame(['alpha:register', 'beta:register', 'alpha:boot', 'beta:boot', 'alpha:shutdown'], $calls);
        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($coordinator): void {
            $coordinator->ready();
        });
    }

    public function test_ready_failure_preserves_primary_and_shuts_down_all_booted_components(): void
    {
        $alpha = $this->declaration('alpha');
        $beta = $this->declaration('beta', ['alpha']);
        $calls = [];
        $readyFailure = new RuntimeException('beta ready failed');
        $shutdownFailure = new RuntimeException('alpha shutdown failed');
        $coordinator = $this->coordinator([
            [$alpha, $this->entryPoint('alpha', $calls, shutdown: static function () use ($shutdownFailure): never {
                throw $shutdownFailure;
            })],
            [$beta, $this->entryPoint('beta', $calls, ready: static function () use ($readyFailure): never {
                throw $readyFailure;
            })],
        ]);
        $registry = new ServiceRegistry();
        $coordinator->register($registry);
        $coordinator->boot($registry->freeze());

        try {
            $coordinator->ready();
            self::fail('Component ready should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ComponentStartupFailed::class, $exception);
            self::assertSame($readyFailure, $exception->getPrevious());
            self::assertSame('ready', $exception->phase());
            self::assertSame('beta', $exception->component()->value());
            self::assertCount(1, $exception->cleanupFailures());
            self::assertSame('alpha', $exception->cleanupFailures()[0]->component()->value());
            self::assertSame($shutdownFailure, $exception->cleanupFailures()[0]->throwable());
        }

        self::assertSame(['alpha:register', 'beta:register', 'alpha:boot', 'beta:boot', 'alpha:ready', 'beta:ready', 'beta:shutdown', 'alpha:shutdown'], $calls);
    }

    public function test_normal_shutdown_aggregates_all_failures_and_never_runs_twice(): void
    {
        $alpha = $this->declaration('alpha');
        $beta = $this->declaration('beta', ['alpha']);
        $calls = [];
        $alphaFailure = new RuntimeException('alpha shutdown failed');
        $betaFailure = new RuntimeException('beta shutdown failed');
        $coordinator = $this->registeredReadyCoordinator([
            [$alpha, $this->entryPoint('alpha', $calls, shutdown: static function () use ($alphaFailure): never {
                throw $alphaFailure;
            })],
            [$beta, $this->entryPoint('beta', $calls, shutdown: static function () use ($betaFailure): never {
                throw $betaFailure;
            })],
        ]);

        try {
            $coordinator->shutdown();
            self::fail('Shutdown should report aggregate failure.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ComponentShutdownFailed::class, $exception);
            self::assertSame($betaFailure, $exception->getPrevious());
            self::assertSame(['beta', 'alpha'], array_map(static fn($failure): string => $failure->component()->value(), $exception->failures()));
            self::assertSame([$betaFailure, $alphaFailure], array_map(static fn($failure): Throwable => $failure->throwable(), $exception->failures()));
        }

        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($coordinator): void {
            $coordinator->shutdown();
        });
        self::assertSame(1, count(array_filter($calls, static fn(string $call): bool => $call === 'alpha:shutdown')));
        self::assertSame(1, count(array_filter($calls, static fn(string $call): bool => $call === 'beta:shutdown')));
    }

    public function test_invalid_and_reentrant_lifecycle_transitions_are_rejected(): void
    {
        $component = $this->declaration('component');
        $calls = [];
        $coordinator = $this->coordinator([[$component, $this->entryPoint('component', $calls)]]);

        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($coordinator): void {
            $coordinator->boot(new ServiceRegistry()->freeze());
        });
        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($coordinator): void {
            $coordinator->ready();
        });
        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($coordinator): void {
            $coordinator->shutdown();
        });

        $registry = new ServiceRegistry();
        $coordinator->register($registry);
        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($coordinator, $registry): void {
            $coordinator->register($registry);
        });
        $coordinator->boot($registry->freeze());
        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($coordinator, $registry): void {
            $coordinator->boot($registry->freeze());
        });
        $coordinator->ready();
        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($coordinator): void {
            $coordinator->ready();
        });

        $reentrantCalls = [];
        $reentrant = null;
        $reentrant = $this->coordinator([
            [$component, $this->entryPoint('component', $reentrantCalls, boot: static function (ComponentBootContext $context) use (&$reentrant): void {
                $reentrant->ready();
            })],
        ]);

        try {
            $this->registerAndBoot($reentrant);
            self::fail('Reentrant lifecycle use should fail startup.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ComponentStartupFailed::class, $exception);
            self::assertInstanceOf(InvalidLifecycleTransition::class, $exception->getPrevious());
        }
    }

    public function test_binding_validation_rejects_missing_extra_duplicate_wrong_object_and_non_binding_before_side_effects(): void
    {
        $alpha = $this->declaration('alpha');
        $beta = $this->declaration('beta');
        $wrongAlpha = $this->declaration('alpha');
        $calls = [];

        $this->assertBindingFailure([$alpha], [], $calls);
        $this->assertBindingFailure([$alpha], [$this->binding($alpha, $this->entryPoint('alpha', $calls)), $this->binding($beta, $this->entryPoint('beta', $calls))], $calls);
        $this->assertBindingFailure([$alpha], [$this->binding($alpha, $this->entryPoint('alpha', $calls)), $this->binding($alpha, $this->entryPoint('alpha-duplicate', $calls))], $calls);
        $this->assertBindingFailure([$alpha], [$this->binding($wrongAlpha, $this->entryPoint('wrong-alpha', $calls))], $calls);
        $this->assertBindingFailure([$alpha], ['not a binding'], $calls);
    }

    public function test_binding_validation_rejects_reused_entry_point_object_before_side_effects(): void
    {
        $alpha = $this->declaration('alpha');
        $beta = $this->declaration('beta');
        $calls = [];
        $sharedEntryPoint = $this->entryPoint('shared', $calls);

        $this->assertThrows(InvalidArgumentException::class, static function () use ($alpha, $beta, $sharedEntryPoint): void {
            new ComponentLifecycleCoordinator(new ResolvedComponentGraph([$alpha, $beta]), [
                new ComponentLifecycleBinding($alpha, $sharedEntryPoint),
                new ComponentLifecycleBinding($beta, $sharedEntryPoint),
            ]);
        });

        self::assertSame([], $calls);

        new ComponentLifecycleCoordinator(new ResolvedComponentGraph([$alpha, $beta]), [
            new ComponentLifecycleBinding($alpha, $this->entryPoint('alpha', $calls)),
            new ComponentLifecycleBinding($beta, $this->entryPoint('beta', $calls)),
        ]);

        self::assertSame([], $calls);
    }

    public function test_registration_failure_prevents_freeze_boot_ready_and_shutdown(): void
    {
        $component = $this->declaration('component');
        $calls = [];
        $registry = new ServiceRegistry();
        $coordinator = $this->coordinator([
            [$component, $this->entryPoint('component', $calls, register: static function (): never {
                throw new RuntimeException('registration failed');
            })],
        ]);

        $this->assertThrows(ComponentServiceRegistrationFailed::class, static function () use ($coordinator, $registry): void {
            $coordinator->register($registry);
        });

        $registry->register('after.failure', ServiceLifetime::Transient, static fn(): string => 'still mutable');
        self::assertTrue($registry->freeze()->has('after.failure'));

        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($coordinator, $registry): void {
            $coordinator->boot($registry->freeze());
        });
        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($coordinator): void {
            $coordinator->ready();
        });
        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($coordinator): void {
            $coordinator->shutdown();
        });
        self::assertSame(['component:register'], $calls);
    }

    /**
     * @param list<array{0: ComponentGraphDeclaration, 1: ComponentEntryPoint}> $entries
     */
    private function registeredReadyCoordinator(array $entries): ComponentLifecycleCoordinator
    {
        $coordinator = $this->coordinator($entries);
        $this->registerBootReady($coordinator);

        return $coordinator;
    }

    private function registerBootReady(ComponentLifecycleCoordinator $coordinator): void
    {
        $registry = new ServiceRegistry();
        $coordinator->register($registry);
        $coordinator->boot($registry->freeze());
        $coordinator->ready();
    }

    private function registerAndBoot(ComponentLifecycleCoordinator $coordinator): void
    {
        $registry = new ServiceRegistry();
        $coordinator->register($registry);
        $coordinator->boot($registry->freeze());
    }

    /**
     * @param list<array{0: ComponentGraphDeclaration, 1: ComponentEntryPoint}> $entries
     */
    private function coordinator(array $entries): ComponentLifecycleCoordinator
    {
        $declarations = [];
        $bindings = [];

        foreach ($entries as [$declaration, $entryPoint]) {
            $declarations[] = $declaration;
            $bindings[] = $this->binding($declaration, $entryPoint);
        }

        return new ComponentLifecycleCoordinator(new ResolvedComponentGraph($declarations), $bindings);
    }

    private function binding(ComponentGraphDeclaration $declaration, object $entryPoint): ComponentLifecycleBinding
    {
        return new ComponentLifecycleBinding($declaration, $entryPoint);
    }

    /**
     * @param list<ComponentGraphDeclaration> $orderedDeclarations
     * @param array<mixed> $bindings
     * @param list<string> $calls
     */
    private function assertBindingFailure(array $orderedDeclarations, array $bindings, array &$calls): void
    {
        $before = $calls;

        $this->assertThrows(InvalidArgumentException::class, static function () use ($orderedDeclarations, $bindings): void {
            new ComponentLifecycleCoordinator(new ResolvedComponentGraph($orderedDeclarations), $bindings);
        });

        self::assertSame($before, $calls);
    }

    /**
     * @param list<string> $dependencies
     */
    private function declaration(string $identifier, array $dependencies = []): ComponentGraphDeclaration
    {
        return new ComponentGraphDeclaration(
            new ComponentIdentifier($identifier),
            new ComponentGraphRelations(array_map(
                static fn(string $dependency): ComponentDependency => new ComponentDependency(new ComponentIdentifier($dependency), ComponentDependencyKind::Required),
                $dependencies,
            )),
        );
    }

    /**
     * @param list<string> $calls
     */
    private function entryPoint(
        string $name,
        array &$calls,
        ?callable $register = null,
        ?callable $boot = null,
        ?callable $ready = null,
        ?callable $shutdown = null,
    ): ComponentEntryPoint {
        $record = static function (string $call) use (&$calls): void {
            $calls[] = $call;
        };

        return new class (
            $name,
            $record,
            $register === null ? null : Closure::fromCallable($register),
            $boot === null ? null : Closure::fromCallable($boot),
            $ready === null ? null : Closure::fromCallable($ready),
            $shutdown === null ? null : Closure::fromCallable($shutdown),
        ) implements ComponentEntryPoint {
            public function __construct(
                private string $name,
                private Closure $record,
                private ?Closure $registerCallback,
                private ?Closure $bootCallback,
                private ?Closure $readyCallback,
                private ?Closure $shutdownCallback,
            ) {}

            public function register(ServiceDefinitionRegistrar $registrar): void
            {
                ($this->record)($this->name . ':register');

                if ($this->registerCallback !== null) {
                    ($this->registerCallback)($registrar);
                }
            }

            public function boot(ComponentBootContext $context): void
            {
                ($this->record)($this->name . ':boot');

                if ($this->bootCallback !== null) {
                    ($this->bootCallback)($context);
                }
            }

            public function ready(): void
            {
                ($this->record)($this->name . ':ready');

                if ($this->readyCallback !== null) {
                    ($this->readyCallback)();
                }
            }

            public function shutdown(): void
            {
                ($this->record)($this->name . ':shutdown');

                if ($this->shutdownCallback !== null) {
                    ($this->shutdownCallback)();
                }
            }
        };
    }

    /**
     * @param callable(): mixed $operation
     */
    private function assertThrows(string $expected, callable $operation): Throwable
    {
        try {
            $operation();
            self::fail('Operation should throw.');
        } catch (Throwable $exception) {
            self::assertInstanceOf($expected, $exception);

            return $exception;
        }
    }
}
