<?php

declare(strict_types=1);

namespace Evolve\Secret\Contracts\Tests\Unit;

use Evolve\Contracts\Exception\EvolveException;
use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Secret\Contracts\Exception\SecretException;
use Evolve\Secret\Contracts\SecretFailureCategory;
use Evolve\Secret\Contracts\SecretName;
use Evolve\Secret\Contracts\SecretResolver;
use Evolve\Secret\Contracts\SecretValue;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;

final class SecretContractsTest extends TestCase
{
    public function test_resolver_has_exact_nullable_resolution_surface(): void
    {
        $reflection = new ReflectionClass(SecretResolver::class);
        self::assertTrue($reflection->isInterface());
        self::assertFalse($reflection->implementsInterface(ResetParticipant::class));
        self::assertSame(['resolve'], array_map(static fn($method): string => $method->getName(), $reflection->getMethods()));
        $method = $reflection->getMethod('resolve');
        self::assertSame('?' . SecretValue::class, (string) $method->getReturnType());
        self::assertSame([SecretName::class], array_map(static fn($parameter): string => (string) $parameter->getType(), $method->getParameters()));
        self::assertSame(['name'], array_map(static fn($parameter): string => $parameter->getName(), $method->getParameters()));

        $resolver = new class implements SecretResolver {
            public function resolve(SecretName $name): ?SecretValue
            {
                return $name->value() === 'present' ? new SecretValue('') : null;
            }
        };
        self::assertNull($resolver->resolve(new SecretName('absent')));
        self::assertSame('', $resolver->resolve(new SecretName('present'))?->value());
    }

    public function test_failure_category_inventory_is_exact(): void
    {
        $actual = [];
        foreach ((new ReflectionEnum(SecretFailureCategory::class))->getCases() as $case) {
            $actual[$case->getName()] = $case->getBackingValue();
        }
        self::assertSame([
            'Authentication' => 'authentication',
            'Authorization' => 'authorization',
            'Timeout' => 'timeout',
            'Unavailable' => 'unavailable',
            'Transport' => 'transport',
            'Unknown' => 'unknown',
        ], $actual);
    }

    public function test_exception_exposes_only_portable_category(): void
    {
        $reflection = new ReflectionClass(SecretException::class);
        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->implementsInterface(EvolveException::class));
        self::assertSame(['category'], array_map(
            static fn($method): string => $method->getName(),
            array_filter($reflection->getMethods(), static fn($method): bool => $method->getDeclaringClass()->getName() === SecretException::class),
        ));
        self::assertSame(SecretFailureCategory::class, (string) $reflection->getMethod('category')->getReturnType());
    }
}
