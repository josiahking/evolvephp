<?php

declare(strict_types=1);

namespace Evolve\Testing\Tests\Unit\Component;

use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Testing\Component\ComponentEntryPointFixture;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class ComponentEntryPointFixtureTest extends TestCase
{
    public function test_all_lifecycle_methods_noop_when_callbacks_are_absent(): void
    {
        $fixture = new ComponentEntryPointFixture();

        $fixture->register(new EntryPointFixtureRegistrar());
        $fixture->boot(new EntryPointFixtureBootContext());
        $fixture->ready();
        $fixture->shutdown();

        self::addToAssertionCount(1);
    }

    public function test_direct_lifecycle_invocations_are_not_once_only_guarded_by_fixture(): void
    {
        $registrations = 0;
        $boots = 0;
        $readyCalls = 0;
        $shutdowns = 0;
        $fixture = new ComponentEntryPointFixture(
            register: static function (ServiceDefinitionRegistrar $registrar) use (&$registrations): void {
                ++$registrations;
            },
            boot: static function (ComponentBootContext $context) use (&$boots): void {
                ++$boots;
            },
            ready: static function () use (&$readyCalls): void {
                ++$readyCalls;
            },
            shutdown: static function () use (&$shutdowns): void {
                ++$shutdowns;
            },
        );
        $registrar = new EntryPointFixtureRegistrar();
        $context = new EntryPointFixtureBootContext();

        $fixture->register($registrar);
        $fixture->register($registrar);
        $fixture->boot($context);
        $fixture->boot($context);
        $fixture->ready();
        $fixture->ready();
        $fixture->shutdown();
        $fixture->shutdown();

        self::assertSame(2, $registrations);
        self::assertSame(2, $boots);
        self::assertSame(2, $readyCalls);
        self::assertSame(2, $shutdowns);
    }

    public function test_register_callback_is_invoked_with_exact_registrar(): void
    {
        $registrar = new EntryPointFixtureRegistrar();
        $calls = 0;
        $forwarded = null;
        $fixture = new ComponentEntryPointFixture(
            register: static function (ServiceDefinitionRegistrar $callbackRegistrar) use (&$calls, &$forwarded): void {
                ++$calls;
                $forwarded = $callbackRegistrar;
            },
        );

        $fixture->register($registrar);

        self::assertSame(1, $calls);
        self::assertSame($registrar, $forwarded);
    }

    public function test_boot_callback_is_invoked_with_exact_context(): void
    {
        $context = new EntryPointFixtureBootContext();
        $calls = 0;
        $forwarded = null;
        $fixture = new ComponentEntryPointFixture(
            boot: static function (ComponentBootContext $callbackContext) use (&$calls, &$forwarded): void {
                ++$calls;
                $forwarded = $callbackContext;
            },
        );

        $fixture->boot($context);

        self::assertSame(1, $calls);
        self::assertSame($context, $forwarded);
    }

    public function test_ready_callback_is_invoked(): void
    {
        $calls = 0;
        $fixture = new ComponentEntryPointFixture(
            ready: static function () use (&$calls): void {
                ++$calls;
            },
        );

        $fixture->ready();

        self::assertSame(1, $calls);
    }

    public function test_shutdown_callback_is_invoked(): void
    {
        $calls = 0;
        $fixture = new ComponentEntryPointFixture(
            shutdown: static function () use (&$calls): void {
                ++$calls;
            },
        );

        $fixture->shutdown();

        self::assertSame(1, $calls);
    }

    public function test_callback_exceptions_propagate_unchanged(): void
    {
        $failure = new RuntimeException('callback failed');
        $fixture = new ComponentEntryPointFixture(
            ready: static function () use ($failure): void {
                throw $failure;
            },
        );

        try {
            $fixture->ready();
            self::fail('Callback failure should propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }
}

final class EntryPointFixtureRegistrar implements ServiceDefinitionRegistrar
{
    public function registerApplication(string $id, callable $factory): void {}

    public function registerExecution(string $id, callable $factory): void {}

    public function registerTransient(string $id, callable $factory): void {}
}

final class EntryPointFixtureBootContext implements ComponentBootContext
{
    public function services(): ContainerInterface
    {
        return new EntryPointFixtureContainer();
    }

    public function deferFailureCleanup(callable $cleanup): void {}
}

final class EntryPointFixtureContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        throw new RuntimeException('No services are available in this test container.');
    }

    public function has(string $id): bool
    {
        return false;
    }
}
