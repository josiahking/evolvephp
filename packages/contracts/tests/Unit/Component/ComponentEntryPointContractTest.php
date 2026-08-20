<?php

declare(strict_types=1);

namespace Evolve\Contracts\Tests\Unit\Component;

use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class ComponentEntryPointContractTest extends TestCase
{
    public function test_component_entry_point_exposes_exact_lifecycle_api(): void
    {
        self::assertTrue(interface_exists(ComponentEntryPoint::class), ComponentEntryPoint::class . ' should exist.');

        $contract = new ReflectionClass(ComponentEntryPoint::class);

        self::assertTrue($contract->isInterface());
        self::assertStringContainsString('@experimental', (string) $contract->getDocComment());
        self::assertSame(['boot', 'ready', 'register', 'shutdown'], $this->methodNames($contract));

        $this->assertVoidMethodWithOneParameter($contract, 'register', 'registrar', ServiceDefinitionRegistrar::class);
        $this->assertVoidMethodWithOneParameter($contract, 'boot', 'context', ComponentBootContext::class);
        $this->assertVoidMethodWithNoParameters($contract, 'ready');
        $this->assertVoidMethodWithNoParameters($contract, 'shutdown');
    }

    /**
     * @param ReflectionClass<ComponentEntryPoint> $contract
     */
    private function assertVoidMethodWithOneParameter(ReflectionClass $contract, string $method, string $parameter, string $type): void
    {
        $reflectedMethod = $contract->getMethod($method);

        self::assertSame(1, $reflectedMethod->getNumberOfParameters());
        self::assertSame($parameter, $reflectedMethod->getParameters()[0]->getName());
        self::assertInstanceOf(ReflectionNamedType::class, $reflectedMethod->getParameters()[0]->getType());
        self::assertSame($type, $reflectedMethod->getParameters()[0]->getType()->getName());
        self::assertInstanceOf(ReflectionNamedType::class, $reflectedMethod->getReturnType());
        self::assertSame('void', $reflectedMethod->getReturnType()->getName());
    }

    /**
     * @param ReflectionClass<ComponentEntryPoint> $contract
     */
    private function assertVoidMethodWithNoParameters(ReflectionClass $contract, string $method): void
    {
        $reflectedMethod = $contract->getMethod($method);

        self::assertSame(0, $reflectedMethod->getNumberOfParameters());
        self::assertInstanceOf(ReflectionNamedType::class, $reflectedMethod->getReturnType());
        self::assertSame('void', $reflectedMethod->getReturnType()->getName());
    }

    /**
     * @param ReflectionClass<ComponentEntryPoint> $contract
     * @return list<string>
     */
    private function methodNames(ReflectionClass $contract): array
    {
        $methods = array_map(static fn($method): string => $method->getName(), $contract->getMethods());
        sort($methods);

        return $methods;
    }
}
