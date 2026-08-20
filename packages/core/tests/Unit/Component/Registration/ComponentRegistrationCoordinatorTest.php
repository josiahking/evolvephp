<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Component\Registration;

use Error;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Contracts\Exception\LifecycleException;
use Evolve\Core\Component\Registration\ComponentRegistration;
use Evolve\Core\Component\Registration\ComponentRegistrationCoordinator;
use Evolve\Core\Component\ResolvedComponentGraph;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ComponentServiceRegistrationFailed;
use Evolve\Core\Exception\InvalidServiceDefinition;
use Evolve\Core\Exception\ServiceRegistryFrozen;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TypeError;

final class ComponentRegistrationCoordinatorTest extends TestCase
{
    public function test_scrambled_registration_input_uses_resolved_graph_order_only(): void
    {
        $alpha = $this->declaration('alpha');
        $beta = $this->declaration('beta');
        $calls = [];
        $registry = new ServiceRegistry();

        $this->coordinator(
            [$alpha, $beta],
            [
                $this->registration($beta, static function (ServiceDefinitionRegistrar $registrar) use (&$calls): void {
                    $calls[] = 'beta';
                    $registrar->registerTransient('beta.service', static fn(): string => 'beta');
                }),
                $this->registration($alpha, static function (ServiceDefinitionRegistrar $registrar) use (&$calls): void {
                    $calls[] = 'alpha';
                    $registrar->registerTransient('alpha.service', static fn(): string => 'alpha');
                }),
            ],
            $registry,
        )->register();

        self::assertSame(['alpha', 'beta'], $calls);
        self::assertTrue($registry->freeze()->has('alpha.service'));
        self::assertTrue($registry->freeze()->has('beta.service'));
    }

    public function test_structural_binding_failures_happen_before_callbacks(): void
    {
        $alpha = $this->declaration('alpha');
        $beta = $this->declaration('beta');
        $clonedAlpha = $this->declaration('alpha');
        $calls = 0;

        $this->assertStructuralFailure([$alpha], [], $calls);
        $this->assertStructuralFailure([$alpha], [$this->registration($alpha, static function () use (&$calls): void {
            ++$calls;
        }), $this->registration($beta, static function () use (&$calls): void {
            ++$calls;
        })], $calls);
        $this->assertStructuralFailure([$alpha], [$this->registration($alpha, static function () use (&$calls): void {
            ++$calls;
        }), $this->registration($alpha, static function () use (&$calls): void {
            ++$calls;
        })], $calls);
        $this->assertStructuralFailure([$alpha], [$this->registration($clonedAlpha, static function () use (&$calls): void {
            ++$calls;
        })], $calls);
    }

    public function test_lifetimes_are_staged_lazily_published_atomically_and_do_not_freeze_registry(): void
    {
        $component = $this->declaration('component');
        $constructed = 0;
        $registry = new ServiceRegistry();

        $this->coordinator([$component], [
            $this->registration($component, static function (ServiceDefinitionRegistrar $registrar) use (&$constructed): void {
                $registrar->registerApplication('application', static function () use (&$constructed): string {
                    ++$constructed;

                    return 'application';
                });
                $registrar->registerExecution('execution', static function () use (&$constructed): string {
                    ++$constructed;

                    return 'execution';
                });
                $registrar->registerTransient('transient', static function () use (&$constructed): string {
                    ++$constructed;

                    return 'transient';
                });
            }),
        ], $registry)->register();

        self::assertSame(0, $constructed);
        $registry->register('after.phase54', ServiceLifetime::Transient, static fn(): string => 'still mutable');

        $container = $registry->freeze();

        self::assertTrue($container->has('application'));
        self::assertTrue($container->has('execution'));
        self::assertTrue($container->has('transient'));
        self::assertSame('still mutable', $container->get('after.phase54'));
    }

    public function test_duplicates_and_existing_collisions_are_attributed_to_active_component_and_publish_nothing(): void
    {
        $alpha = $this->declaration('alpha');
        $beta = $this->declaration('beta');

        $this->assertRegistrationFailurePublishesNothing(
            [$alpha],
            [
                $this->registration($alpha, static function (ServiceDefinitionRegistrar $registrar): void {
                    $registrar->registerApplication('dup', static fn(): string => 'first');
                    $registrar->registerTransient('dup', static fn(): string => 'second');
                }),
            ],
            'alpha',
        );

        $laterCalled = false;
        $this->assertRegistrationFailurePublishesNothing(
            [$alpha, $beta],
            [
                $this->registration($alpha, static function (ServiceDefinitionRegistrar $registrar): void {
                    $registrar->registerApplication('shared', static fn(): string => 'alpha');
                }),
                $this->registration($beta, static function (ServiceDefinitionRegistrar $registrar) use (&$laterCalled): void {
                    $registrar->registerExecution('shared', static fn(): string => 'beta');
                    $laterCalled = true;
                }),
            ],
            'beta',
        );
        self::assertFalse($laterCalled);

        $registry = new ServiceRegistry();
        $registry->register('existing', ServiceLifetime::Application, static fn(): string => 'existing');

        $this->assertRegistrationFailurePublishesNothing(
            [$alpha, $beta],
            [
                $this->registration($alpha, static function (ServiceDefinitionRegistrar $registrar): void {
                    $registrar->registerApplication('existing', static fn(): string => 'collision');
                }),
                $this->registration($beta, static function (ServiceDefinitionRegistrar $registrar): void {
                    $registrar->registerApplication('later', static fn(): string => 'later');
                }),
            ],
            'alpha',
            $registry,
        );
        self::assertFalse($registry->freeze()->has('later'));
    }

    public function test_component_throwables_are_wrapped_with_previous_and_component_identifier(): void
    {
        $component = $this->declaration('component');

        foreach ([new RuntimeException('boom'), new Error('boom'), new TypeError('boom')] as $throwable) {
            $registry = new ServiceRegistry();
            $coordinator = $this->coordinator([$component], [
                $this->registration($component, static function () use ($throwable): never {
                    throw $throwable;
                }),
            ], $registry);

            try {
                $coordinator->register();
                self::fail('Component registration should fail.');
            } catch (Throwable $exception) {
                self::assertInstanceOf(ComponentServiceRegistrationFailed::class, $exception);
                self::assertContains(LifecycleException::class, class_implements($exception));
                self::assertSame($throwable, $exception->getPrevious());
                self::assertSame('component', $exception->component()->value());
            }
        }
    }

    public function test_failed_successful_and_reentrant_coordinators_are_terminal(): void
    {
        $component = $this->declaration('component');
        $failed = $this->coordinator([$component], [
            $this->registration($component, static function (): never {
                throw new RuntimeException('failed');
            }),
        ]);

        $this->catchThrowable(static function () use ($failed): void {
            $failed->register();
        });
        $this->assertThrows(LogicException::class, static function () use ($failed): void {
            $failed->register();
        });

        $successful = $this->coordinator([$component], [
            $this->registration($component, static function (ServiceDefinitionRegistrar $registrar): void {
                $registrar->registerTransient('service', static fn(): string => 'ok');
            }),
        ]);
        $successful->register();
        $this->assertThrows(LogicException::class, static function () use ($successful): void {
            $successful->register();
        });

        $reentrant = null;
        $reentrant = $this->coordinator([$component], [
            $this->registration($component, static function () use (&$reentrant): void {
                $reentrant->register();
            }),
        ]);

        $this->assertThrows(ComponentServiceRegistrationFailed::class, static function () use ($reentrant): void {
            $reentrant->register();
        });
    }

    public function test_retained_registrar_is_inert_and_exposes_no_read_or_lifecycle_authority(): void
    {
        $component = $this->declaration('component');
        $retained = null;

        $this->coordinator([$component], [
            $this->registration($component, static function (ServiceDefinitionRegistrar $registrar) use (&$retained): void {
                $retained = $registrar;
                $registrar->registerTransient('service', static fn(): string => 'ok');
            }),
        ])->register();

        self::assertInstanceOf(ServiceDefinitionRegistrar::class, $retained);

        foreach (['get', 'has', 'freeze', 'createExecutionScope', 'register', 'remove', 'replace'] as $method) {
            self::assertFalse(method_exists($retained, $method));
        }

        $this->assertThrows(LogicException::class, static function () use ($retained): void {
            $retained->registerTransient('later', static fn(): string => 'later');
        });
    }

    public function test_frozen_target_is_rejected_before_callbacks(): void
    {
        $component = $this->declaration('component');
        $registry = new ServiceRegistry();
        $registry->freeze();
        $called = false;

        $this->assertThrows(ServiceRegistryFrozen::class, function () use ($component, $registry, &$called): void {
            $this->coordinator([$component], [
                $this->registration($component, static function () use (&$called): void {
                    $called = true;
                }),
            ], $registry)->register();
        });

        self::assertFalse($called);
    }

    public function test_final_live_preflight_failure_publishes_nothing(): void
    {
        $alpha = $this->declaration('alpha');
        $beta = $this->declaration('beta');
        $registry = new ServiceRegistry();

        $this->assertThrows(InvalidServiceDefinition::class, static function () use ($alpha, $beta, $registry): void {
            (new ComponentRegistrationCoordinator(new ResolvedComponentGraph([$alpha, $beta]), $registry, [
                new ComponentRegistration($alpha, static function (ServiceDefinitionRegistrar $registrar) use ($registry): void {
                    $registrar->registerApplication('alpha.service', static fn(): string => 'alpha');
                    $registry->register('beta.service', ServiceLifetime::Application, static fn(): string => 'external side effect');
                }),
                new ComponentRegistration($beta, static function (ServiceDefinitionRegistrar $registrar): void {
                    $registrar->registerApplication('beta.service', static fn(): string => 'beta');
                }),
            ]))->register();
        });

        $container = $registry->freeze();
        self::assertFalse($container->has('alpha.service'));
        self::assertSame('external side effect', $container->get('beta.service'));
    }

    /**
     * @param list<ComponentGraphDeclaration> $orderedDeclarations
     * @param list<ComponentRegistration> $registrations
     */
    private function assertStructuralFailure(array $orderedDeclarations, array $registrations, int &$calls): void
    {
        $before = $calls;

        $this->assertThrows(InvalidArgumentException::class, function () use ($orderedDeclarations, $registrations): void {
            $this->coordinator($orderedDeclarations, $registrations)->register();
        });

        self::assertSame($before, $calls);
    }

    /**
     * @param list<ComponentGraphDeclaration> $orderedDeclarations
     * @param list<ComponentRegistration> $registrations
     */
    private function assertRegistrationFailurePublishesNothing(array $orderedDeclarations, array $registrations, string $component, ?ServiceRegistry $registry = null): void
    {
        $registry ??= new ServiceRegistry();

        try {
            $this->coordinator($orderedDeclarations, $registrations, $registry)->register();
            self::fail('Component registration should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ComponentServiceRegistrationFailed::class, $exception);
            self::assertSame($component, $exception->component()->value());
        }

        foreach (['dup', 'shared'] as $id) {
            self::assertFalse($registry->freeze()->has($id));
        }
    }

    /**
     * @param list<ComponentGraphDeclaration> $orderedDeclarations
     * @param list<ComponentRegistration> $registrations
     */
    private function coordinator(array $orderedDeclarations, array $registrations, ?ServiceRegistry $registry = null): ComponentRegistrationCoordinator
    {
        return new ComponentRegistrationCoordinator(new ResolvedComponentGraph($orderedDeclarations), $registry ?? new ServiceRegistry(), $registrations);
    }

    private function registration(ComponentGraphDeclaration $declaration, callable $callback): ComponentRegistration
    {
        return new ComponentRegistration($declaration, $callback);
    }

    private function declaration(string $identifier): ComponentGraphDeclaration
    {
        return new ComponentGraphDeclaration(new ComponentIdentifier($identifier));
    }

    private function assertThrows(string $expected, callable $operation): void
    {
        self::assertInstanceOf($expected, $this->catchThrowable($operation));
    }

    private function catchThrowable(callable $operation): Throwable
    {
        try {
            $operation();
            self::fail('Operation should throw.');
        } catch (Throwable $exception) {
            return $exception;
        }
    }
}
