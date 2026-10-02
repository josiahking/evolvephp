<?php

declare(strict_types=1);

namespace Evolve\Scheduler;

use DateTimeZone;
use Evolve\Core\Execution\ExecutionContextValues;
use InvalidArgumentException;

/** @experimental This API may change before stable release. */
final readonly class ScheduleDefinition
{
    private DateTimeZone $zone;
    private OverlapProtection $overlap;

    public function __construct(
        private string $identifier,
        private CronSchedule $cron,
        private ScheduledAction $action,
        private string $timezone = 'UTC',
        private ?string $locale = null,
        private CatchUpPolicy $catchUpPolicy = CatchUpPolicy::Skip,
        ?OverlapProtection $overlapProtection = null,
    ) {
        self::assertIdentifier($identifier);
        new ExecutionContextValues($locale, $timezone);
        $this->zone = new DateTimeZone($timezone);
        $this->overlap = $overlapProtection ?? OverlapProtection::allow();
    }

    public static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/\A[a-z0-9_-]+(?:\.[a-z0-9_-]+)*\z/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Schedule identifier must contain dot-separated lowercase ASCII segments.');
        }
    }

    public function identifier(): string
    {
        return $this->identifier;
    }
    public function cron(): CronSchedule
    {
        return $this->cron;
    }
    public function action(): ScheduledAction
    {
        return $this->action;
    }
    public function timezone(): string
    {
        return $this->timezone;
    }
    public function dateTimeZone(): DateTimeZone
    {
        return $this->zone;
    }
    public function locale(): ?string
    {
        return $this->locale;
    }
    public function catchUpPolicy(): CatchUpPolicy
    {
        return $this->catchUpPolicy;
    }
    public function overlapProtection(): OverlapProtection
    {
        return $this->overlap;
    }
}
