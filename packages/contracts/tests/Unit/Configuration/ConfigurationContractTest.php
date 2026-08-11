<?php

declare(strict_types=1);

namespace Evolve\Contracts\Tests\Unit\Configuration;

use Evolve\Contracts\Configuration\Configuration;
use Evolve\Contracts\Configuration\ConfigurationValidator;
use Evolve\Contracts\Exception\ConfigurationException;
use Evolve\Contracts\Exception\EvolveException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

final class ConfigurationContractTest extends TestCase
{
    public function test_configuration_exposes_read_only_lookup_surface(): void
    {
        self::assertTrue(interface_exists(Configuration::class), Configuration::class . ' should exist.');

        $contract = new ReflectionClass(Configuration::class);

        self::assertTrue($contract->isInterface());
        self::assertSame(['all', 'get', 'has', 'require'], $this->publicMethodNames($contract));

        $this->assertMethodSignature($contract, 'has', ['key' => 'string'], 'bool');
        $this->assertMethodSignature($contract, 'get', ['key' => 'string', 'default' => 'mixed'], 'mixed');
        $this->assertMethodSignature($contract, 'require', ['key' => 'string'], 'mixed');
        $this->assertMethodSignature($contract, 'all', [], 'array');

        $defaultParameter = $contract->getMethod('get')->getParameters()[1];

        self::assertTrue($defaultParameter->isDefaultValueAvailable());
        self::assertNull($defaultParameter->getDefaultValue());

        foreach (['set', 'replace', 'merge', 'remove', 'clear', 'freeze', 'isFrozen', 'load', 'environment', 'schema'] as $method) {
            self::assertFalse($contract->hasMethod($method), Configuration::class . ' must not expose ' . $method . '().');
        }
    }

    public function test_configuration_validator_exposes_only_validate(): void
    {
        self::assertTrue(interface_exists(Configuration::class), Configuration::class . ' should exist.');
        self::assertTrue(interface_exists(ConfigurationValidator::class), ConfigurationValidator::class . ' should exist.');

        $contract = new ReflectionClass(ConfigurationValidator::class);

        self::assertTrue($contract->isInterface());
        self::assertSame(['validate'], $this->publicMethodNames($contract));
        $this->assertMethodSignature($contract, 'validate', ['configuration' => Configuration::class], 'void');

        foreach (['supports', 'rules', 'schema', 'violations', 'errors', 'result', 'priority', 'name'] as $method) {
            self::assertFalse($contract->hasMethod($method), ConfigurationValidator::class . ' must not expose ' . $method . '().');
        }
    }

    public function test_configuration_exception_extends_evolve_exception_without_methods(): void
    {
        self::assertTrue(interface_exists(EvolveException::class), EvolveException::class . ' should exist.');
        self::assertTrue(interface_exists(ConfigurationException::class), ConfigurationException::class . ' should exist.');

        $contract = new ReflectionClass(ConfigurationException::class);

        self::assertTrue($contract->isInterface());
        self::assertTrue($contract->implementsInterface(EvolveException::class));
        self::assertSame([], $this->declaredMethodNames($contract));
    }

    /**
     * @template T of object
     *
     * @param ReflectionClass<T> $contract
     * @param array<string, string> $expectedParameters
     */
    private function assertMethodSignature(ReflectionClass $contract, string $methodName, array $expectedParameters, string $expectedReturnType): void
    {
        self::assertTrue($contract->hasMethod($methodName), $contract->getName() . ' should expose ' . $methodName . '().');

        $method = $contract->getMethod($methodName);

        self::assertSame(array_keys($expectedParameters), array_map(
            static fn(ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters(),
        ));

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            self::assertInstanceOf(ReflectionNamedType::class, $type, $contract->getName() . '::' . $methodName . '() parameter should declare a named type.');
            self::assertSame($expectedParameters[$parameter->getName()], $type->getName());
        }

        $returnType = $method->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $returnType, $contract->getName() . '::' . $methodName . '() should declare a named return type.');
        self::assertSame($expectedReturnType, $returnType->getName());
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
}
