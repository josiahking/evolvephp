<?php

declare(strict_types=1);

namespace Evolve\Scheduler\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Evolve\Scheduler\CronSchedule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CronScheduleTest extends TestCase
{
    public function test_invalid_expression_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CronSchedule('invalid cron');
    }

    public function test_due_evaluation_uses_explicit_timezone_across_dst(): void
    {
        $schedule = new CronSchedule('30 2 * * *');
        $zone = new DateTimeZone('Europe/Paris');

        self::assertSame('2026-03-29T01:30:00+00:00', $schedule->latestDueOccurrence(new DateTimeImmutable('2026-03-29T00:00:00Z'), new DateTimeImmutable('2026-03-29T03:00:00Z'), $zone)?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'));
        self::assertSame('2026-03-30T00:30:00+00:00', $schedule->latestDueOccurrence(new DateTimeImmutable('2026-03-29T00:00:00Z'), new DateTimeImmutable('2026-03-30T01:00:00Z'), $zone)?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'));
    }

    public function test_default_timezone_does_not_change_explicit_due_result(): void
    {
        $original = date_default_timezone_get();
        $schedule = new CronSchedule('* * * * *');
        $zone = new DateTimeZone('UTC');
        try {
            date_default_timezone_set('Pacific/Honolulu');
            $first = $schedule->latestDueOccurrence(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T00:03:00Z'), $zone);
            date_default_timezone_set('Asia/Tokyo');
            $second = $schedule->latestDueOccurrence(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T00:03:00Z'), $zone);
            self::assertEquals($first, $second);
        } finally {
            date_default_timezone_set($original);
        }
    }
}
