<?php

declare(strict_types=1);

namespace Evolve\Cache\Memory\Tests\Unit;

use Evolve\Cache\Memory\Exception\InvalidCacheKeyException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;
use ReflectionClass;

final class InvalidCacheKeyExceptionTest extends TestCase
{
    public function testExceptionImplementsPsrInvalidArgumentContract(): void
    {
        $exception = new InvalidCacheKeyException('Invalid cache key.');
        $reflection = new ReflectionClass(InvalidCacheKeyException::class);

        self::assertSame(InvalidArgumentException::class, $reflection->getParentClass()->getName());
        self::assertContains(PsrInvalidArgumentException::class, class_implements(InvalidCacheKeyException::class));
        self::assertSame('Invalid cache key.', $exception->getMessage());
    }
}
