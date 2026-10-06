<?php

declare(strict_types=1);

namespace Evolve\Job;

use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Queue\Contracts\MessageEnvelope;

/** @experimental EvolvePHP 2 is pre-beta; this job API may change before stable release. */
interface JobExecutionContext
{
    /** @param callable(): ExecutionOutcome $execution */
    public function run(MessageEnvelope $message, callable $execution): ExecutionOutcome;
}
