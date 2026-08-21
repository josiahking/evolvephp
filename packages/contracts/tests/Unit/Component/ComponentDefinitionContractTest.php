<?php

declare(strict_types=1);

namespace Evolve\Contracts\Tests\Unit\Component;

use Evolve\Contracts\Component\ComponentDefinition;
use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\ComponentType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class ComponentDefinitionContractTest extends TestCase
{
    public function test_component_definition_contract_has_exact_experimental_surface(): void
    {
        self::assertTrue(interface_exists(ComponentDefinition::class), ComponentDefinition::class . ' should exist.');

        $contract = new ReflectionClass(ComponentDefinition::class);
        $docComment = $contract->getDocComment();

        self::assertIsString($docComment);
        self::assertStringContainsString('@experimental', $docComment);
        self::assertSame(
            ['createEntryPoint', 'graphDeclaration', 'identifier', 'type', 'validate'],
            $this->methodNames($contract),
        );
        $this->assertReturnType($contract, 'identifier', ComponentIdentifier::class);
        $this->assertReturnType($contract, 'type', ComponentType::class);
        $this->assertReturnType($contract, 'graphDeclaration', ComponentGraphDeclaration::class);
        $this->assertReturnType($contract, 'validate', 'void');
        $this->assertReturnType($contract, 'createEntryPoint', ComponentEntryPoint::class);
    }

    /**
     * @param ReflectionClass<ComponentDefinition> $contract
     * @return list<string>
     */
    private function methodNames(ReflectionClass $contract): array
    {
        $names = array_map(static fn($method): string => $method->getName(), $contract->getMethods());
        sort($names);

        return $names;
    }

    /**
     * @param ReflectionClass<ComponentDefinition> $contract
     */
    private function assertReturnType(ReflectionClass $contract, string $methodName, string $expected): void
    {
        $method = $contract->getMethod($methodName);

        self::assertSame([], $method->getParameters(), $methodName . ' must not accept parameters.');
        self::assertInstanceOf(ReflectionNamedType::class, $method->getReturnType());
        self::assertSame($expected, $method->getReturnType()->getName());
    }
}
