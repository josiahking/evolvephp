<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Container;

use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionScopeUnavailable;
use Evolve\Core\Exception\InvalidServiceDefinition;
use Evolve\Core\Exception\ServiceRegistryFrozen;
use Evolve\Core\Execution\ExecutionScope;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionEnum;
use ReflectionProperty;
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

    public function test_execution_lifetime_is_accepted_during_freeze_without_constructing_service(): void
    {
        $constructed = 0;
        $registry = new ServiceRegistry();
        $registry->register('execution.service', ServiceLifetime::Execution, static function () use (&$constructed): object {
            ++$constructed;

            return new \stdClass();
        });

        $container = $registry->freeze();
        $second = $registry->freeze();

        self::assertSame($container, $second);
        self::assertTrue($container->has('execution.service'));
        self::assertSame(0, $constructed);
    }

    public function test_create_execution_scope_requires_prior_successful_freeze_without_mutating_registry(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('execution.service', ServiceLifetime::Execution, static fn(): object => new \stdClass());

        try {
            $registry->createExecutionScope();
            self::fail('Execution scope creation before freeze should fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ExecutionScopeUnavailable::class, $exception);
            self::assertContains(ContainerExceptionInterface::class, class_implements($exception));
        }

        $registry->register('later', ServiceLifetime::Application, static fn(): string => 'ok');

        $container = $registry->freeze();

        self::assertTrue($container->has('execution.service'));
        self::assertTrue($container->has('later'));
    }

    public function test_create_execution_scope_returns_independent_psr11_scope_after_freeze(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('execution.service', ServiceLifetime::Execution, static fn(): object => new \stdClass());

        $registry->freeze();

        $first = $registry->createExecutionScope();
        $second = $registry->createExecutionScope();

        self::assertContains(ExecutionScope::class, class_implements($first));
        self::assertContains(ContainerInterface::class, class_implements($first));
        self::assertNotSame($first, $second);
        self::assertNotContains(ContainerInterface::class, class_implements($registry));

        $this->expectContainerBootstrapFailure(ServiceRegistryFrozen::class, static function () use ($registry): void {
            $registry->register('later', ServiceLifetime::Application, static fn(): object => new \stdClass());
        });
    }

    public function test_numeric_looking_service_identifiers_use_prefixed_internal_definition_keys(): void
    {
        $registry = new ServiceRegistry();

        foreach (['1', '01', '-1', '+1', '0'] as $id) {
            $registry->register($id, ServiceLifetime::Transient, static fn(): string => $id);
        }

        self::assertSame(
            ['service:1', 'service:01', 'service:-1', 'service:+1', 'service:0'],
            array_keys($this->propertyValue($registry, 'definitions')),
        );

        $container = $registry->freeze();

        foreach (['1', '01', '-1', '+1', '0'] as $id) {
            self::assertTrue($container->has($id));
            self::assertSame($id, $container->get($id));
        }
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

    private function propertyValue(object $object, string $property): mixed
    {
        $reflection = new ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}
