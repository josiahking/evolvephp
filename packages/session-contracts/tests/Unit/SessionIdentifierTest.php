<?php

declare(strict_types=1);

namespace Evolve\Session\Contracts\Tests\Unit;

use Evolve\Session\Contracts\SessionIdentifier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SensitiveParameter;

final class SessionIdentifierTest extends TestCase
{
    public function testSessionIdentifierIsFinalReadonlyAndPreservesAcceptedValueExactly(): void
    {
        self::assertTrue(class_exists(SessionIdentifier::class));

        $reflection = new ReflectionClass(SessionIdentifier::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertFalse($reflection->hasMethod('__toString'));
        self::assertTrue($reflection->hasProperty('value'));
        self::assertTrue($reflection->getProperty('value')->isPrivate());
        self::assertSame('string', (string) $reflection->getProperty('value')->getType());

        $identifier = new SessionIdentifier(" id \t\n");

        self::assertSame(" id \t\n", $identifier->value());
    }

    public function testEmptyIdentifierIsRejectedWithoutLeakingTheInputValue(): void
    {
        try {
            new SessionIdentifier('');
            self::fail('Empty session identifiers must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('""', $exception->getMessage());
            self::assertStringNotContainsString("''", $exception->getMessage());
        }
    }

    public function testConstructorParameterIsSensitiveAndDebugInfoRedactsRawValue(): void
    {
        $constructor = (new ReflectionClass(SessionIdentifier::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertSame(['value'], array_map(static fn($parameter) => $parameter->getName(), $constructor->getParameters()));
        self::assertSame('string', (string) $constructor->getParameters()[0]->getType());
        self::assertCount(1, $constructor->getParameters()[0]->getAttributes(SensitiveParameter::class));

        $identifier = new SessionIdentifier('secret-session-id');

        self::assertSame(['value' => '[REDACTED]'], $identifier->__debugInfo());
        self::assertStringNotContainsString('secret-session-id', print_r($identifier, true));
    }
}
