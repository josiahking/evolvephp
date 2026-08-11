<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Container;

use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\InvalidServiceDefinition;
use Evolve\Core\Exception\ServiceRegistryFrozen;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionEnum;
use Throwable;

final class ServiceRegistryTest extends TestCase
{
    public function test_service_lifetime_declares_exact_phase33_vocabulary(): void
    {
        self::assertTrue(enum_exists(ServiceLifetime::class), ServiceLifetime::class . ' should exist.');

        $enum = new ReflectionEnum(ServiceLifetime::class);

        self::assertSame(
            ['Application', 'Execution', 'Transient'],
            array_map(static fn($case): string => $case->getName(), $enum->getCases()),
        );
    }

    public function test_empty_identifier_is_rejected_without_trimming_other_identifiers(): void
    {
        $registry = new ServiceRegistry();

        $this->expectContainerBootstrapFailure(InvalidServiceDefinition::class, static function () use ($registry): void {
            $registry->register('', ServiceLifetime::Application, static fn(): object => new \stdClass());
        });

        $whitespaceRegistry = new ServiceRegistry();
        $whitespaceRegistry->register(' ', ServiceLifetime::Application, static fn(): object => new \stdClass());

        self::assertTrue($whitespaceRegistry->freeze()->has(' '));
    }

    public function test_duplicate_identifier_is_rejected_immediately(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('database', ServiceLifetime::Application, static fn(): object => new \stdClass());

        $this->expectContainerBootstrapFailure(InvalidServiceDefinition::class, static function () use ($registry): void {
            $registry->register('database', ServiceLifetime::Transient, static fn(): object => new \stdClass());
        });
    }

    public function test_freeze_is_idempotent_and_does_not_construct_services(): void
    {
        $constructed = 0;
        $registry = new ServiceRegistry();
        $registry->register('lazy', ServiceLifetime::Application, static function () use (&$constructed): object {
            ++$constructed;

            return new \stdClass();
        });

        $first = $registry->freeze();
        $second = $registry->freeze();

        self::assertContains(ContainerInterface::class, class_implements($first));
        self::assertSame($first, $second);
        self::assertSame(0, $constructed);
    }

    public function test_registration_after_successful_freeze_is_rejected(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('clock', ServiceLifetime::Application, static fn(): object => new \stdClass());

        $registry->freeze();

        $this->expectContainerBootstrapFailure(ServiceRegistryFrozen::class, static function () use ($registry): void {
            $registry->register('logger', ServiceLifetime::Application, static fn(): object => new \stdClass());
        });
    }

    public function test_execution_lifetime_is_reserved_and_rejected_during_freeze(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('execution.service', ServiceLifetime::Execution, static fn(): object => new \stdClass());

        $this->expectContainerBootstrapFailure(InvalidServiceDefinition::class, static function () use ($registry): void {
            $registry->freeze();
        });
    }

    public function test_failed_freeze_leaves_registry_terminally_frozen(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('execution.service', ServiceLifetime::Execution, static fn(): object => new \stdClass());

        $this->expectContainerBootstrapFailure(InvalidServiceDefinition::class, static function () use ($registry): void {
            $registry->freeze();
        });

        $this->expectContainerBootstrapFailure(ServiceRegistryFrozen::class, static function () use ($registry): void {
            $registry->register('later', ServiceLifetime::Application, static fn(): object => new \stdClass());
        });
    }

    /**
     * @param class-string<Throwable> $expected
     * @param callable(): mixed $operation
     */
    private function expectContainerBootstrapFailure(string $expected, callable $operation): void
    {
        self::assertTrue(class_exists($expected), $expected . ' should exist.');

        try {
            $operation();
            self::fail('Container bootstrap operation should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf($expected, $exception);
        }
    }
}
