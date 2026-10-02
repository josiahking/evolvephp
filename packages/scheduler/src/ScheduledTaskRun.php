<?php

declare(strict_types=1);

namespace Evolve\Scheduler;

use DateTimeImmutable;
use Evolve\Core\Execution\ExecutionOutcome;

/** @experimental This API may change before stable release. */
final readonly class ScheduledTaskRun
{
    public function __construct(private string $scheduleIdentifier, private DateTimeImmutable $scheduledOccurrence, private ExecutionOutcome $executionOutcome) {}

    public function scheduleIdentifier(): string
    {
        return $this->scheduleIdentifier;
    }
    public function scheduledOccurrence(): DateTimeImmutable
    {
        return $this->scheduledOccurrence;
    }
    public function executionOutcome(): ExecutionOutcome
    {
        return $this->executionOutcome;
    }
}
