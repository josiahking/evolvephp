<?php

declare(strict_types=1);

namespace Evolve\Lock\Contracts\Tests\Unit;

use Evolve\Contracts\Exception\EvolveException;
use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Lock\Contracts\Exception\LockException;
use Evolve\Lock\Contracts\Lease;
use Evolve\Lock\Contracts\LeaseDuration;
use Evolve\Lock\Contracts\LockKey;
use Evolve\Lock\Contracts\LockProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class LockContractsTest extends TestCase
{
    public function test_lease_is_reset_participant_and_exposes_only_renew_release_and_reset(): void
    {
        $lease = new ReflectionClass(Lease::class);

        self::assertTrue($lease->isInterface());
        self::assertTrue($lease->implementsInterface(ResetParticipant::class));
        self::assertSame([ResetParticipant::class], $lease->getInterfaceNames());
        self::assertSame(['release', 'renew', 'reset'], $this->publicMethodNames($lease));

        $renew = $lease->getMethod('renew');
        self::assertSame('bool', (string) $renew->getReturnType());
        self::assertSame([LeaseDuration::class], array_map(
            static fn($parameter): string => (string) $parameter->getType(),
            $renew->getParameters(),
        ));

        $this->assertNoArgumentMethod($lease->getMethod('release'), 'void');
        $this->assertNoArgumentMethod($lease->getMethod('reset'), 'void');
    }

    public function test_provider_has_one_non_blocking_attempt_and_nullable_contention_result(): void
    {
        $provider = new ReflectionClass(LockProvider::class);

        self::assertTrue($provider->isInterface());
        self::assertSame(['tryAcquire'], $this->publicMethodNames($provider));

        $method = $provider->getMethod('tryAcquire');
        self::assertSame('?' . Lease::class, (string) $method->getReturnType());
        self::assertSame([LockKey::class, LeaseDuration::class], array_map(
            static fn($parameter): string => (string) $parameter->getType(),
            $method->getParameters(),
        ));
    }

    public function test_lock_exception_is_the_narrow_evolve_exception_boundary(): void
    {
        $exception = new ReflectionClass(LockException::class);

        self::assertTrue($exception->isInterface());
        self::assertTrue($exception->implementsInterface(EvolveException::class));
        self::assertContains(EvolveException::class, $exception->getInterfaceNames());
        self::assertSame([], array_values(array_filter(
            $this->publicMethodNames($exception),
            static fn(string $method): bool => !in_array($method, [
                '__toString', 'getCode', 'getFile', 'getLine', 'getMessage', 'getPrevious',
                'getTrace', 'getTraceAsString',
            ], true),
        )));
    }

    /**
     * @template T of object
     *
     * @param ReflectionClass<T> $reflection
     *
     * @return list<string>
     */
    private function publicMethodNames(ReflectionClass $reflection): array
    {
        $names = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($names);

        return $names;
    }

    private function assertNoArgumentMethod(ReflectionMethod $method, string $returnType): void
    {
        self::assertSame($returnType, (string) $method->getReturnType());
        self::assertSame([], $method->getParameters());
    }
}
