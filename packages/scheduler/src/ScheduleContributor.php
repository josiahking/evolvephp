<?php

declare(strict_types=1);

namespace Evolve\Scheduler;

interface ScheduleContributor
{
    public function identifier(): string;

    /** @return iterable<ScheduleDefinition> */
    public function definitions(): iterable;
}
