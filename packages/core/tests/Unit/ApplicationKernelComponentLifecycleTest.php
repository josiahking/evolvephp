<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit;

use Closure;
use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Contracts\Configuration\Configuration;
use Evolve\Contracts\Configuration\ConfigurationValidator;
use Evolve\Contracts\Exception\ConfigurationException;
use Evolve\Contracts\Exception\LifecycleException;
use Evolve\Core\ApplicationKernel;
use Evolve\Core\Component\Lifecycle\ComponentLifecycleBinding;
use Evolve\Core\Component\Lifecycle\ComponentLifecycleCoordinator;
use Evolve\Core\Component\ResolvedComponentGraph;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ComponentShutdownFailed;
use Evolve\Core\Exception\ComponentStartupFailed;
use Evolve\Core\Exception\InvalidLifecycleTransition;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class ApplicationKernelComponentLifecycleTest extends TestCase
{
    public function test_application_kernel_runs_registration_freeze_boot_ready_in_order(): void
    {
        self::assertTrue(class_exists(ComponentLifecycleCoordinator::class), ComponentLifecycleCoordinator::class . ' should exist.');

        $component = $this->declaration('component');
        $calls = [];
        $registry = new ServiceRegistry();
        $coordinator = $this->coordinator([
            [$component, $this->entryPoint('component', $calls, register: static function (ServiceDefinitionRegistrar $registrar) use (&$calls): void {
                $calls[] = 'register:registry-mutable';
                $registrar->registerTransient('component.service', static function () use (&$calls): string {
                    $calls[] = 'factory';

                    return 'component';
                });
            }, boot: static function (ComponentBootContext $context) use (&$calls): void {
                $calls[] = 'boot:has:' . ($context->services()->has('component.service') ? 'yes' : 'no');
                $calls[] = 'boot:factory-not-run-yet';
            })],
        ]);

        $kernel = new ApplicationKernel(null, [$this->validator($calls, 'validator')], $registry, $coordinator);
        $kernel->boot();

        self::assertSame([
            'validator',
            'component:register',
            'register:registry-mutable',
            'component:boot',
            'boot:has:yes',
            'boot:factory-not-run-yet',
            'component:ready',
        ], $calls);
    }

    public function test_kernel_can_create_minimal_registry_when_components_are_configured_without_services(): void
    {
        $component = $this->declaration('component');
        $calls = [];
        $coordinator = $this->coordinator([
            [$component, $this->entryPoint('component', $calls, register: static function (ServiceDefinitionRegistrar $registrar): void {
                $registrar->registerTransient('component.service', static fn(): string => 'component');
            }, boot: static function (ComponentBootContext $context): void {
                self::assertTrue($context->services()->has('component.service'));
            })],
        ]);
        $kernel = new ApplicationKernel(null, [], null, $coordinator);

        $kernel->boot();
        $kernel->shutdown();

        self::assertSame(['component:register', 'component:boot', 'component:ready', 'component:shutdown'], $calls);
    }

    public function test_configuration_validation_failure_prevents_component_registration(): void
    {
        $component = $this->declaration('component');
        $calls = [];
        $coordinator = $this->coordinator([[$component, $this->entryPoint('component', $calls)]]);
        $registry = new ServiceRegistry();
        $kernel = new ApplicationKernel(null, [$this->failingValidator()], $registry, $coordinator);

        $this->assertThrows(ConfigurationException::class, static function () use ($kernel): void {
            $kernel->boot();
        });

        $registry->register('still.mutable', ServiceLifetime::Transient, static fn(): string => 'ok');
        self::assertTrue($registry->freeze()->has('still.mutable'));
        self::assertSame([], $calls);
    }

    public function test_registration_failure_prevents_kernel_freeze_boot_and_ready(): void
    {
        $component = $this->declaration('component');
        $calls = [];
        $registry = new ServiceRegistry();
        $coordinator = $this->coordinator([
            [$component, $this->entryPoint('component', $calls, register: static function (): never {
                throw new RuntimeException('registration failed');
            })],
        ]);
        $kernel = new ApplicationKernel(null, [], $registry, $coordinator);

        $this->assertThrows(LifecycleException::class, static function () use ($kernel): void {
            $kernel->boot();
        });

        $registry->register('after.failure', ServiceLifetime::Transient, static fn(): string => 'ok');
        self::assertTrue($registry->freeze()->has('after.failure'));
        self::assertSame(['component:register'], $calls);
    }

    public function test_boot_failure_prevents_kernel_ready_and_retry(): void
    {
        $component = $this->declaration('component');
        $calls = [];
        $bootFailure = new RuntimeException('boot failed');
        $kernel = new ApplicationKernel(null, [], new ServiceRegistry(), $this->coordinator([
            [$component, $this->entryPoint('component', $calls, boot: static function () use ($bootFailure): never {
                throw $bootFailure;
            })],
        ]));

        $exception = $this->assertThrows(ComponentStartupFailed::class, static function () use ($kernel): void {
            $kernel->boot();
        });
        self::assertSame($bootFailure, $exception->getPrevious());

        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($kernel): void {
            $kernel->boot();
        });
        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($kernel): void {
            $kernel->shutdown();
        });
        self::assertSame(['component:register', 'component:boot'], $calls);
    }

    public function test_ready_failure_prevents_kernel_ready_and_shuts_down_booted_components(): void
    {
        $component = $this->declaration('component');
        $calls = [];
        $readyFailure = new RuntimeException('ready failed');
        $kernel = new ApplicationKernel(null, [], new ServiceRegistry(), $this->coordinator([
            [$component, $this->entryPoint('component', $calls, ready: static function () use ($readyFailure): never {
                throw $readyFailure;
            })],
        ]));

        $exception = $this->assertThrows(ComponentStartupFailed::class, static function () use ($kernel): void {
            $kernel->boot();
        });
        self::assertInstanceOf(ComponentStartupFailed::class, $exception);
        self::assertSame($readyFailure, $exception->getPrevious());
        self::assertSame('ready', $exception->phase());
        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($kernel): void {
            $kernel->shutdown();
        });
        self::assertSame(['component:register', 'component:boot', 'component:ready', 'component:shutdown'], $calls);
    }

    public function test_default_no_component_kernel_still_boots_and_shuts_down(): void
    {
        $kernel = new ApplicationKernel();

        $kernel->boot();
        $kernel->shutdown();

        $this->addToAssertionCount(1);
    }

    public function test_normal_kernel_shutdown_invokes_components_first_and_only_once(): void
    {
        $component = $this->declaration('component');
        $calls = [];
        $kernel = new ApplicationKernel(null, [], new ServiceRegistry(), $this->coordinator([
            [$component, $this->entryPoint('component', $calls)],
        ]));

        $kernel->boot();
        $kernel->shutdown();

        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($kernel): void {
            $kernel->shutdown();
        });
        self::assertSame(['component:register', 'component:boot', 'component:ready', 'component:shutdown'], $calls);
    }

    public function test_kernel_becomes_terminal_even_when_component_shutdown_reports_failures(): void
    {
        $component = $this->declaration('component');
        $calls = [];
        $kernel = new ApplicationKernel(null, [], new ServiceRegistry(), $this->coordinator([
            [$component, $this->entryPoint('component', $calls, shutdown: static function (): never {
                throw new RuntimeException('shutdown failed');
            })],
        ]));

        $kernel->boot();
        $this->assertThrows(ComponentShutdownFailed::class, static function () use ($kernel): void {
            $kernel->shutdown();
        });
        $this->assertThrows(InvalidLifecycleTransition::class, static function () use ($kernel): void {
            $kernel->shutdown();
        });
        self::assertSame(1, count(array_filter($calls, static fn(string $call): bool => $call === 'component:shutdown')));
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
            $bindings[] = new ComponentLifecycleBinding($declaration, $entryPoint);
        }

        return new ComponentLifecycleCoordinator(new ResolvedComponentGraph($declarations), $bindings);
    }

    private function declaration(string $identifier): ComponentGraphDeclaration
    {
        return new ComponentGraphDeclaration(new ComponentIdentifier($identifier));
    }

    /**
     * @param list<string> $calls
     */
    private function validator(array &$calls, string $label): ConfigurationValidator
    {
        $record = static function (string $call) use (&$calls): void {
            $calls[] = $call;
        };

        return new class ($record, $label) implements ConfigurationValidator {
            public function __construct(private Closure $record, private string $label) {}

            public function validate(Configuration $configuration): void
            {
                ($this->record)($this->label);
            }
        };
    }

    private function failingValidator(): ConfigurationValidator
    {
        return new class implements ConfigurationValidator {
            public function validate(Configuration $configuration): void
            {
                throw new RuntimeException('invalid configuration');
            }
        };
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
