<?php

declare(strict_types=1);

namespace Evolve\Scheduler;

use DateTimeImmutable;
use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionContextValues;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Core\Execution\ProcessReuseDecision;
use Evolve\Lock\Contracts\LockKey;
use Evolve\Lock\Contracts\LockProvider;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/** @experimental This API may change before stable release. */
final class SchedulerRunner
{
    private bool $quarantined = false;

    public function __construct(private ScheduleRegistry $registry, private ClockInterface $clock, private ExecutionOrchestrator $orchestrator, private ?LockProvider $locks = null)
    {
        foreach ($registry->definitions() as $definition) {
            if ($definition->overlapProtection()->preventsOverlap() && $locks === null) {
                throw new InvalidArgumentException('Prevent-overlap schedule requires a lock provider.');
            }
        }
    }

    public function runTick(?DateTimeImmutable $previousCheckExclusive = null): SchedulerRunReport
    {
        if ($this->quarantined) {
            throw new ExecutionStartFailed('Scheduler runner is quarantined and cannot accept more work.');
        }

        $checkedAt = $this->clock->now();
        if ($previousCheckExclusive !== null && $previousCheckExclusive > $checkedAt) {
            throw new InvalidArgumentException('Previous check must not be later than checked instant.');
        }

        $runs = [];
        $decision = ProcessReuseDecision::Reusable;
        foreach ($this->registry->definitions() as $definition) {
            $cron = $definition->cron();
            $zone = $definition->dateTimeZone();
            $occurrence = $previousCheckExclusive !== null && $definition->catchUpPolicy() === CatchUpPolicy::RunOnce
                ? $cron->latestDueOccurrence($previousCheckExclusive, $checkedAt, $zone)
                : $cron->currentDueOccurrence($checkedAt, $zone);
            if ($occurrence === null) {
                continue;
            }

            try {
                $outcome = $this->orchestrator->execute(
                    ExecutionKind::ScheduledJob,
                    function (ExecutionContext $context, ExecutionScope $scope) use ($definition): ScheduleInvocationResult {
                        $overlap = $definition->overlapProtection();
                        if ($overlap->preventsOverlap()) {
                            $lease = $this->locks->tryAcquire(new LockKey('scheduler:' . $definition->identifier()), $overlap->duration());
                            if ($lease === null) {
                                return ScheduleInvocationResult::overlapSkipped();
                            }
                            $scope->registerResetParticipant('scheduler.lease.' . $definition->identifier(), $lease);
                        }

                        return ScheduleInvocationResult::executed($definition->action()->invoke($context, $scope));
                    },
                    new ExecutionContextValues($definition->locale(), $definition->timezone()),
                );
            } catch (ExecutionStartFailed $failure) {
                $this->quarantined = true;
                throw $failure;
            }

            $runs[] = new ScheduledTaskRun($definition->identifier(), $occurrence, $outcome);
            if ($outcome->requiresQuarantine()) {
                $this->quarantined = true;
                $decision = ProcessReuseDecision::QuarantineRequired;
                break;
            }
        }

        return new SchedulerRunReport($checkedAt, $previousCheckExclusive, $runs, $decision);
    }
}
