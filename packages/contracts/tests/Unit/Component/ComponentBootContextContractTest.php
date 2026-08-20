<?php

declare(strict_types=1);

namespace Evolve\Contracts\Tests\Unit\Component;

use Evolve\Contracts\Component\ComponentBootContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;

final class ComponentBootContextContractTest extends TestCase
{
    public function test_component_boot_context_exposes_exact_public_experimental_api(): void
    {
        self::assertTrue(interface_exists(ComponentBootContext::class), ComponentBootContext::class . ' should exist.');

        $contract = new ReflectionClass(ComponentBootContext::class);

        self::assertTrue($contract->isInterface());
        self::assertStringContainsString('@experimental', (string) $contract->getDocComment());
        self::assertSame(['deferFailureCleanup', 'services'], $this->methodNames($contract));

        $services = $contract->getMethod('services');
        self::assertSame(0, $services->getNumberOfParameters());
        self::assertInstanceOf(ReflectionNamedType::class, $services->getReturnType());
        self::assertSame(ContainerInterface::class, $services->getReturnType()->getName());

        $cleanup = $contract->getMethod('deferFailureCleanup');
        self::assertSame(1, $cleanup->getNumberOfParameters());
        self::assertSame('cleanup', $cleanup->getParameters()[0]->getName());
        self::assertInstanceOf(ReflectionNamedType::class, $cleanup->getParameters()[0]->getType());
        self::assertSame('callable', $cleanup->getParameters()[0]->getType()->getName());
        self::assertInstanceOf(ReflectionNamedType::class, $cleanup->getReturnType());
        self::assertSame('void', $cleanup->getReturnType()->getName());
    }

    /**
     * @param ReflectionClass<ComponentBootContext> $contract
     * @return list<string>
     */
    private function methodNames(ReflectionClass $contract): array
    {
        $methods = array_map(static fn($method): string => $method->getName(), $contract->getMethods());
        sort($methods);

        return $methods;
    }
}
