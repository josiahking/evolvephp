<?php

declare(strict_types=1);

namespace Evolve\Storage\Contracts\Tests\Unit;

use Evolve\Storage\Contracts\StorageKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

final class StorageKeyTest extends TestCase
{
    public function test_empty_key_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StorageKey('');
    }

    public function test_one_byte_and_exactly_1024_byte_keys_are_accepted(): void
    {
        self::assertSame('x', (new StorageKey('x'))->value());
        self::assertSame(str_repeat('x', 1024), (new StorageKey(str_repeat('x', 1024)))->value());
    }

    public function test_key_longer_than_1024_bytes_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StorageKey(str_repeat('x', 1025));
    }

    public function test_embedded_nul_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StorageKey("before\0after");
    }

    public function test_zero_and_whitespace_only_values_are_accepted_exactly(): void
    {
        self::assertSame('0', (new StorageKey('0'))->value());
        self::assertSame(" \t\n ", (new StorageKey(" \t\n "))->value());
    }

    public function test_leading_and_trailing_whitespace_and_repeated_slashes_are_preserved(): void
    {
        $value = " /folder///object/ ";

        self::assertSame($value, (new StorageKey($value))->value());
    }

    public function test_dot_components_are_not_interpreted(): void
    {
        $value = './folder/../object';

        self::assertSame($value, (new StorageKey($value))->value());
    }

    public function test_multibyte_and_arbitrary_non_nul_bytes_are_preserved_within_byte_limit(): void
    {
        $value = "東京/é/\xFF";

        self::assertLessThanOrEqual(1024, strlen($value));
        self::assertSame($value, (new StorageKey($value))->value());
    }

    public function test_key_is_final_readonly_sensitive_and_redacts_debug_output(): void
    {
        $secret = 'private-object-name';
        $key = new StorageKey($secret);
        $reflection = new \ReflectionClass(StorageKey::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertContains(SensitiveParameter::class, array_map(
            static fn($attribute): string => $attribute->getName(),
            $constructor->getParameters()[0]->getAttributes(),
        ));
        $publicMethods = array_map(
            static fn($method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($publicMethods);
        self::assertSame(['__construct', '__debugInfo', 'value'], $publicMethods);
        self::assertStringNotContainsString($secret, serialize($key->__debugInfo()));
        self::assertStringNotContainsString($secret, print_r($key, true));
    }

    public function test_value_is_exact_and_string_conversion_is_not_exposed(): void
    {
        $value = " exact/key ";
        $key = new StorageKey($value);

        self::assertSame($value, $key->value());
        self::assertFalse((new \ReflectionClass(StorageKey::class))->hasMethod('__toString'));
    }
}
