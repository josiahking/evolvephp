<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Container;

use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ServiceNotFound;
use Evolve\Core\Exception\ServiceResolutionFailed;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Throwable;

final class ServiceContainerTest extends TestCase
{
    public function test_frozen_registry_returns_psr11_read_only_container(): void
    {
        $container = $this->containerWith('service', ServiceLifetime::Application, static fn(): object => new \stdClass());

        self::assertContains(ContainerInterface::class, class_implements($container));
    }

    public function test_has_reports_known_and_unknown_ids_without_invoking_factory(): void
    {
        $calls = 0;
        $container = $this->containerWith('known', ServiceLifetime::Application, static function () use (&$calls): object {
            ++$calls;

            return new \stdClass();
        });

        self::assertTrue($container->has('known'));
        self::assertFalse($container->has('unknown'));
        self::assertSame(0, $calls);
    }

    public function test_unknown_get_fails_through_not_found_boundary(): void
    {
        $container = (new ServiceRegistry())->freeze();

        try {
            $container->get('missing');
            self::fail('Unknown service should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ServiceNotFound::class, $exception);
            self::assertContains(NotFoundExceptionInterface::class, class_implements($exception));
        }
    }

    public function test_application_lifetime_is_lazy_and_caches_only_successful_results(): void
    {
        $calls = 0;
        $container = $this->containerWith('application', ServiceLifetime::Application, static function () use (&$calls): object {
            ++$calls;

            if ($calls === 1) {
                throw new RuntimeException('First construction fails.');
            }

            return new \stdClass();
        });

        $this->ignoreResolutionFailure($container, 'application');

        $first = $container->get('application');
        $second = $container->get('application');

        self::assertSame($first, $second);
        self::assertSame(2, $calls);
    }

    public function test_transient_lifetime_executes_factory_for_every_get(): void
    {
        $calls = 0;
        $container = $this->containerWith('transient', ServiceLifetime::Transient, static function () use (&$calls): object {
            ++$calls;

            return new \stdClass();
        });

        $first = $container->get('transient');
        $second = $container->get('transient');

        self::assertNotSame($first, $second);
        self::assertSame(2, $calls);
    }

    public function test_factories_receive_read_only_container_and_can_resolve_nested_dependencies(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('dependency', ServiceLifetime::Application, static fn(): string => 'value');
        $registry->register('dependent', ServiceLifetime::Transient, static function (ContainerInterface $container): string {
            return 'resolved:' . $container->get('dependency');
        });

        self::assertSame('resolved:value', $registry->freeze()->get('dependent'));
    }

    public function test_circular_dependencies_fail_through_container_exception_boundary_and_cleanup_stack(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('a', ServiceLifetime::Transient, static fn(ContainerInterface $container): mixed => $container->get('b'));
        $registry->register('b', ServiceLifetime::Transient, static fn(ContainerInterface $container): mixed => $container->get('a'));
        $registry->register('healthy', ServiceLifetime::Transient, static fn(): string => 'ok');

        $container = $registry->freeze();

        $this->assertResolutionFailure($container, 'a');

        self::assertSame('ok', $container->get('healthy'));
    }

    public function test_factory_failures_are_wrapped_with_previous_exception(): void
    {
        $failure = new RuntimeException('Factory failed.');
        $container = $this->containerWith('broken', ServiceLifetime::Transient, static function () use ($failure): never {
            throw $failure;
        });

        try {
            $container->get('broken');
            self::fail('Broken factory should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ServiceResolutionFailed::class, $exception);
            self::assertContains(ContainerExceptionInterface::class, class_implements($exception));
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function test_nested_not_found_and_container_exceptions_remain_catchable_through_psr_boundaries(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('missing-dependent', ServiceLifetime::Transient, static fn(ContainerInterface $container): mixed => $container->get('missing'));
        $registry->register('self-cycle', ServiceLifetime::Transient, static fn(ContainerInterface $container): mixed => $container->get('self-cycle'));

        $container = $registry->freeze();

        try {
            $container->get('missing-dependent');
            self::fail('Nested missing service should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(NotFoundExceptionInterface::class, $exception);
        }

        $this->assertResolutionFailure($container, 'self-cycle');
    }

    private function containerWith(string $id, ServiceLifetime $lifetime, callable $factory): ContainerInterface
    {
        $registry = new ServiceRegistry();
        $registry->register($id, $lifetime, $factory);

        return $registry->freeze();
    }

    private function ignoreResolutionFailure(ContainerInterface $container, string $id): void
    {
        try {
            $container->get($id);
            self::fail('Resolution should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ContainerExceptionInterface::class, $exception);
        }
    }

    private function assertResolutionFailure(ContainerInterface $container, string $id): void
    {
        try {
            $container->get($id);
            self::fail('Resolution should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ServiceResolutionFailed::class, $exception);
            self::assertContains(ContainerExceptionInterface::class, class_implements($exception));
        }
    }
}
