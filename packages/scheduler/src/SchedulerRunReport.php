<?php

declare(strict_types=1);

namespace Evolve\Scheduler;

use DateTimeImmutable;
use Evolve\Core\Execution\ProcessReuseDecision;

/** @experimental This API may change before stable release. */
final readonly class SchedulerRunReport
{
    /** @var list<ScheduledTaskRun> */
    private array $runs;

    /** @param list<ScheduledTaskRun> $runs */
    public function __construct(private DateTimeImmutable $checkedAt, private ?DateTimeImmutable $previousCheckExclusive, array $runs, private ProcessReuseDecision $reuseDecision)
    {
        $this->runs = $runs;
    }

    public function checkedAt(): DateTimeImmutable
    {
        return $this->checkedAt;
    }
    public function previousCheckExclusive(): ?DateTimeImmutable
    {
        return $this->previousCheckExclusive;
    }
    /** @return list<ScheduledTaskRun> */
    public function runs(): array
    {
        return $this->runs;
    }
    public function reuseDecision(): ProcessReuseDecision
    {
        return $this->reuseDecision;
    }
}
