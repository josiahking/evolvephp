<?php

declare(strict_types=1);

namespace Evolve\Session\Contracts\Tests\Unit;

use ArrayAccess;
use Evolve\Session\Contracts\Session;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SessionContractTest extends TestCase
{
    public function testSessionInterfaceDeclaresExactMutableDataVocabulary(): void
    {
        self::assertTrue(interface_exists(Session::class));

        $reflection = new ReflectionClass(Session::class);

        self::assertTrue($reflection->isInterface());
        self::assertSame([], $reflection->getInterfaceNames());
        self::assertSame(['has', 'get', 'set', 'remove', 'clear'], array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(),
        ));

        $this->assertMethodSignature($reflection->getMethod('has'), 'bool', ['key' => 'string']);
        $this->assertMethodSignature($reflection->getMethod('get'), 'mixed', ['key' => 'string', 'default' => 'mixed'], ['default' => null]);
        $this->assertMethodSignature($reflection->getMethod('set'), 'void', ['key' => 'string', 'value' => 'mixed']);
        $this->assertMethodSignature($reflection->getMethod('remove'), 'void', ['key' => 'string']);
        $this->assertMethodSignature($reflection->getMethod('clear'), 'void', []);
    }

    public function testSessionContractAvoidsBulkMagicFlashAndArrayExpansion(): void
    {
        $reflection = new ReflectionClass(Session::class);

        foreach (['all', 'export', 'flash', 'csrf', '__get', '__set', '__isset', '__unset', '__serialize', '__unserialize'] as $method) {
            self::assertFalse($reflection->hasMethod($method), Session::class . ' must not declare ' . $method . '().');
        }

        self::assertFalse($reflection->implementsInterface(ArrayAccess::class));
    }

    /**
     * @param array<string, string> $parameterTypes
     * @param array<string, mixed> $defaultValues
     */
    private function assertMethodSignature(ReflectionMethod $method, string $returnType, array $parameterTypes, array $defaultValues = []): void
    {
        self::assertSame($returnType, (string) $method->getReturnType());
        self::assertSame(array_keys($parameterTypes), array_map(static fn($parameter) => $parameter->getName(), $method->getParameters()));

        foreach ($method->getParameters() as $parameter) {
            self::assertSame($parameterTypes[$parameter->getName()], (string) $parameter->getType());

            if (array_key_exists($parameter->getName(), $defaultValues)) {
                self::assertTrue($parameter->isDefaultValueAvailable());
                self::assertSame($defaultValues[$parameter->getName()], $parameter->getDefaultValue());
            } else {
                self::assertFalse($parameter->isDefaultValueAvailable());
            }
        }
    }
}
