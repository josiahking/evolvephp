<?php

declare(strict_types=1);

namespace Evolve\Lock\Contracts\Tests\Unit;

use Evolve\Lock\Contracts\LeaseDuration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class LeaseDurationTest extends TestCase
{
    public function test_duration_is_immutable_and_preserves_positive_milliseconds(): void
    {
        $duration = new LeaseDuration(123456);

        self::assertSame(123456, $duration->milliseconds());
        self::assertTrue((new ReflectionClass(LeaseDuration::class))->isReadOnly());
    }

    #[DataProvider('nonPositiveDurations')]
    public function test_zero_or_negative_duration_is_rejected(int $milliseconds): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LeaseDuration($milliseconds);
    }

    /** @return iterable<string, array{int}> */
    public static function nonPositiveDurations(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }
}
