<?php

declare(strict_types=1);

namespace Evolve\Session\Contracts\Tests\Unit;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Session\Contracts\Session;
use Evolve\Session\Contracts\SessionAdapter;
use Evolve\Session\Contracts\SessionIdentifier;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SessionAdapterContractTest extends TestCase
{
    public function testSessionAdapterExtendsResetParticipantAndDeclaresExactLifecycleMethods(): void
    {
        self::assertTrue(interface_exists(SessionAdapter::class));

        $reflection = new ReflectionClass(SessionAdapter::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->implementsInterface(ResetParticipant::class));
        self::assertSame([ResetParticipant::class], $reflection->getInterfaceNames());
        self::assertSame(['open', 'identifier', 'regenerate', 'invalidate', 'close', 'reset'], array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(),
        ));

        $open = $reflection->getMethod('open');
        self::assertSame(Session::class, (string) $open->getReturnType());
        self::assertSame(['identifier'], array_map(static fn($parameter) => $parameter->getName(), $open->getParameters()));
        self::assertSame('?' . SessionIdentifier::class, (string) $open->getParameters()[0]->getType());
        self::assertTrue($open->getParameters()[0]->isDefaultValueAvailable());
        self::assertNull($open->getParameters()[0]->getDefaultValue());

        $this->assertNoParameterMethod($reflection->getMethod('identifier'), SessionIdentifier::class);
        $this->assertNoParameterMethod($reflection->getMethod('regenerate'), SessionIdentifier::class);
        $this->assertNoParameterMethod($reflection->getMethod('invalidate'), 'void');
        $this->assertNoParameterMethod($reflection->getMethod('close'), 'void');
        $this->assertNoParameterMethod($reflection->getMethod('reset'), 'void');
    }

    public function testSessionAdapterDoesNotDeclareNativeStorageOrAmbientApis(): void
    {
        $reflection = new ReflectionClass(SessionAdapter::class);

        foreach (['start', 'destroy', 'writeClose', 'sessionId', 'read', 'write', 'gc', 'lock', 'unlock', 'cookie', 'flash'] as $method) {
            self::assertFalse($reflection->hasMethod($method), SessionAdapter::class . ' must not declare ' . $method . '().');
        }
    }

    private function assertNoParameterMethod(ReflectionMethod $method, string $returnType): void
    {
        self::assertSame($returnType, (string) $method->getReturnType());
        self::assertSame([], $method->getParameters());
    }
}
