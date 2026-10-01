<?php

declare(strict_types=1);

namespace Evolve\Secret\Contracts\Tests\Unit;

use Evolve\Secret\Contracts\SecretName;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SensitiveParameter;

final class SecretNameTest extends TestCase
{
    public function test_empty_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SecretName('');
    }

    public function test_name_above_2048_bytes_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SecretName(str_repeat('x', 2049));
    }

    public function test_embedded_nul_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SecretName("before\0after");
    }

    public function test_exact_byte_limit_and_opaque_names_are_preserved(): void
    {
        foreach ([str_repeat('x', 2048), " \t\n ", './a/../b:field', "\xFF\xFE/name"] as $value) {
            self::assertSame($value, (new SecretName($value))->value());
        }
    }

    public function test_name_is_final_readonly_sensitive_and_redacted(): void
    {
        $value = 'private-name-for-debug-check';
        $name = new SecretName($value);
        $reflection = new ReflectionClass(SecretName::class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertSame([SensitiveParameter::class], array_map(
            static fn($attribute): string => $attribute->getName(),
            $reflection->getConstructor()->getParameters()[0]->getAttributes(),
        ));
        $methods = array_map(static fn($method): string => $method->getName(), $reflection->getMethods(ReflectionMethod::IS_PUBLIC));
        sort($methods);
        self::assertSame(['__construct', '__debugInfo', 'value'], $methods);
        self::assertFalse($reflection->hasMethod('__toString'));
        self::assertSame(['value' => '[REDACTED]'], $name->__debugInfo());
        self::assertStringNotContainsString($value, print_r($name, true));
    }
}
