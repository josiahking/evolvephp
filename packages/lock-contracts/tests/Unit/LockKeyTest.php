<?php

declare(strict_types=1);

namespace Evolve\Lock\Contracts\Tests\Unit;

use Evolve\Lock\Contracts\LockKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SensitiveParameter;

final class LockKeyTest extends TestCase
{
    public function test_lock_key_is_immutable_and_preserves_accepted_value_exactly(): void
    {
        $reflection = new ReflectionClass(LockKey::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertFalse($reflection->hasMethod('__toString'));

        $key = new LockKey(" key \t\n");

        self::assertSame(" key \t\n", $key->value());
    }

    public function test_empty_lock_key_is_rejected_without_leaking_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LockKey('');
    }

    public function test_constructor_marks_key_sensitive_and_debug_output_is_redacted(): void
    {
        $constructor = (new ReflectionClass(LockKey::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertSame(['value'], array_map(static fn($parameter) => $parameter->getName(), $constructor->getParameters()));
        self::assertCount(1, $constructor->getParameters()[0]->getAttributes(SensitiveParameter::class));

        $key = new LockKey('secret-lock-key');

        self::assertSame(['value' => '[REDACTED]'], $key->__debugInfo());
        self::assertStringNotContainsString('secret-lock-key', print_r($key, true));
    }
}
