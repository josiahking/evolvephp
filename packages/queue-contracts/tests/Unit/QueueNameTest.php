<?php

declare(strict_types=1);

namespace Evolve\Queue\Contracts\Tests\Unit;

use Evolve\Queue\Contracts\QueueName;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SensitiveParameter;

final class QueueNameTest extends TestCase
{
    public function test_queue_name_is_immutable_and_preserves_accepted_value_exactly(): void
    {
        $reflection = new ReflectionClass(QueueName::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertFalse($reflection->hasMethod('__toString'));

        $name = new QueueName(" Queue/Name \t\n");

        self::assertSame(" Queue/Name \t\n", $name->value());
    }

    public function test_empty_queue_name_is_rejected_without_leaking_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new QueueName('');
    }

    public function test_whitespace_queue_name_is_accepted_without_trimming(): void
    {
        self::assertSame(" \t ", (new QueueName(" \t "))->value());
    }

    public function test_constructor_marks_name_sensitive_and_debug_output_is_redacted(): void
    {
        $constructor = (new ReflectionClass(QueueName::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertSame(['value'], array_map(static fn($parameter) => $parameter->getName(), $constructor->getParameters()));
        self::assertCount(1, $constructor->getParameters()[0]->getAttributes(SensitiveParameter::class));

        $name = new QueueName('secret-queue-name');

        self::assertSame(['value' => '[REDACTED]'], $name->__debugInfo());
        self::assertStringNotContainsString('secret-queue-name', print_r($name, true));
    }
}
