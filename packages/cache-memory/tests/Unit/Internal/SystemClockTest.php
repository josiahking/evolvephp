<?php

declare(strict_types=1);

namespace Evolve\Cache\Memory\Tests\Unit\Internal;

use DateTimeImmutable;
use Evolve\Cache\Memory\Internal\SystemClock;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class SystemClockTest extends TestCase
{
    public function testSystemClockImplementsPsrClockAndReturnsImmutableTime(): void
    {
        $before = new DateTimeImmutable();
        $clock = new SystemClock();
        $now = $clock->now();
        $after = new DateTimeImmutable();

        self::assertContains(ClockInterface::class, class_implements(SystemClock::class));
        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual($after, $now);
    }
}
