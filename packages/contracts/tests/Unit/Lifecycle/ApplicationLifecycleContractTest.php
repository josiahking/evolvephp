<?php

declare(strict_types=1);

namespace Evolve\Contracts\Tests\Unit\Lifecycle;

use Evolve\Contracts\Exception\EvolveException;
use Evolve\Contracts\Exception\LifecycleException;
use Evolve\Contracts\Lifecycle\ApplicationLifecycle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

final class ApplicationLifecycleContractTest extends TestCase
{
    public function test_evolve_exception_is_public_throwable_marker_contract(): void
    {
        self::assertTrue(interface_exists(EvolveException::class), EvolveException::class . ' should exist.');

        $contract = new ReflectionClass(EvolveException::class);

        self::assertTrue($contract->isInterface());
        self::assertTrue($contract->implementsInterface(Throwable::class));
        self::assertSame([], $this->declaredMethodNames($contract));
    }

    public function test_lifecycle_exception_extends_evolve_exception_without_methods(): void
    {
        self::assertTrue(interface_exists(LifecycleException::class), LifecycleException::class . ' should exist.');

        $contract = new ReflectionClass(LifecycleException::class);

        self::assertTrue($contract->isInterface());
        self::assertTrue($contract->implementsInterface(EvolveException::class));
        self::assertSame([], $this->declaredMethodNames($contract));
    }

    public function test_application_lifecycle_exposes_only_boot_and_shutdown(): void
    {
        self::assertTrue(interface_exists(ApplicationLifecycle::class), ApplicationLifecycle::class . ' should exist.');

        $contract = new ReflectionClass(ApplicationLifecycle::class);

        self::assertTrue($contract->isInterface());
        self::assertSame(['boot', 'shutdown'], $this->publicMethodNames($contract));
        $this->assertVoidNoArgumentMethod($contract, 'boot');
        $this->assertVoidNoArgumentMethod($contract, 'shutdown');

        foreach (['handle', 'reset', 'container', 'config'] as $method) {
            self::assertFalse($contract->hasMethod($method), ApplicationLifecycle::class . ' must not expose ' . $method . '().');
        }
    }

    /**
     * @template T of object
     *
     * @param ReflectionClass<T> $contract
     *
     * @return list<string>
     */
    private function publicMethodNames(ReflectionClass $contract): array
    {
        $methodNames = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $contract->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methodNames);

        return $methodNames;
    }

    /**
     * @template T of object
     *
     * @param ReflectionClass<T> $contract
     *
     * @return list<string>
     */
    private function declaredMethodNames(ReflectionClass $contract): array
    {
        $methodNames = [];

        foreach ($contract->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $contract->getName()) {
                $methodNames[] = $method->getName();
            }
        }

        sort($methodNames);

        return $methodNames;
    }

    /**
     * @template T of object
     *
     * @param ReflectionClass<T> $contract
     */
    private function assertVoidNoArgumentMethod(ReflectionClass $contract, string $methodName): void
    {
        self::assertTrue($contract->hasMethod($methodName), $contract->getName() . ' should expose ' . $methodName . '().');

        $method = $contract->getMethod($methodName);
        $returnType = $method->getReturnType();

        self::assertSame(0, $method->getNumberOfParameters());
        self::assertInstanceOf(ReflectionNamedType::class, $returnType, $contract->getName() . '::' . $methodName . '() should declare a named return type.');
        self::assertSame('void', $returnType->getName());
    }
}
