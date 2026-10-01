<?php

declare(strict_types=1);

namespace Evolve\Secret\Contracts\Tests\Unit;

use Evolve\Secret\Contracts\SecretValue;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SensitiveParameter;

final class SecretValueTest extends TestCase
{
    public function test_empty_text_and_binary_values_are_preserved_exactly(): void
    {
        foreach (['', 'ordinary text', "a\0b", "\xFF\xFE\0\x80"] as $value) {
            self::assertSame($value, (new SecretValue($value))->value());
        }
    }

    public function test_value_is_final_readonly_sensitive_and_redacted(): void
    {
        $value = 'private-value-for-debug-check';
        $secret = new SecretValue($value);
        $reflection = new ReflectionClass(SecretValue::class);
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
        self::assertSame(['value' => '[REDACTED]'], $secret->__debugInfo());
        self::assertStringNotContainsString($value, print_r($secret, true));
    }
}
