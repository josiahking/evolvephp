<?php

declare(strict_types=1);

namespace Evolve\Scheduler;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/** @experimental This API may change before stable release. */
final readonly class CronSchedule
{
    private CronExpression $cron;

    public function __construct(private string $expression)
    {
        try {
            $this->cron = new CronExpression($expression);
            if (!CronExpression::isValidExpression($expression)) {
                throw new InvalidArgumentException('Invalid cron expression.');
            }
        } catch (Throwable $failure) {
            throw new InvalidArgumentException('Invalid cron expression.', 0, $failure);
        }
    }

    public function expression(): string
    {
        return $this->expression;
    }

    public function isDue(DateTimeImmutable $checkedAt, DateTimeZone $timezone): bool
    {
        return $this->cron->isDue($checkedAt, $timezone->getName());
    }

    public function latestDueOccurrence(DateTimeImmutable $previousCheckExclusive, DateTimeImmutable $nowInclusive, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if ($previousCheckExclusive > $nowInclusive) {
            throw new InvalidArgumentException('Previous check must not be later than now.');
        }

        $latest = DateTimeImmutable::createFromInterface($this->cron->getPreviousRunDate($nowInclusive, 0, true, $timezone->getName()));

        return $latest > $previousCheckExclusive && $latest <= $nowInclusive ? $latest : null;
    }

    public function currentDueOccurrence(DateTimeImmutable $checkedAt, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if (!$this->isDue($checkedAt, $timezone)) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($this->cron->getPreviousRunDate($checkedAt, 0, true, $timezone->getName()));
    }
}
