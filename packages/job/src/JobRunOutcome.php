<?php

declare(strict_types=1);

namespace Evolve\Job;

use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Core\Execution\ProcessReuseDecision;
use Evolve\Queue\Contracts\Exception\QueueException;
use Evolve\Queue\Contracts\QueueOperation;
use LogicException;

/** @experimental EvolvePHP 2 is pre-beta; this job API may change before stable release. */
final readonly class JobRunOutcome
{
    private function __construct(
        private ?ExecutionOutcome $executionOutcome,
        private ?QueueException $queueFailure,
        private JobSettlementState $settlement,
        private ProcessReuseDecision $reuseDecision,
    ) {}

    public static function idle(): self
    {
        return new self(null, null, JobSettlementState::NotAttempted, ProcessReuseDecision::Reusable);
    }

    public static function receiveFailed(QueueException $failure): self
    {
        if ($failure->operation() !== QueueOperation::Receive) {
            throw new LogicException('Receive failure must report the receive operation.');
        }

        return new self(null, $failure, JobSettlementState::NotAttempted, ProcessReuseDecision::Reusable);
    }

    public static function executed(
        ExecutionOutcome $execution,
        JobSettlementState $settlement,
        ?QueueException $failure = null,
    ): self {
        if ($execution->requiresQuarantine()) {
            if ($settlement !== JobSettlementState::NotAttempted || $failure !== null) {
                throw new LogicException('Core quarantine prevents queue settlement.');
            }

            return new self($execution, null, $settlement, ProcessReuseDecision::QuarantineRequired);
        }

        if ($settlement === JobSettlementState::NotAttempted
            || ($execution->primarySucceeded() && !in_array($settlement, [JobSettlementState::Acknowledged, JobSettlementState::AcknowledgeFailed], true))
            || ($execution->primaryFailed() && !in_array($settlement, [JobSettlementState::Rejected, JobSettlementState::RejectFailed], true))) {
            throw new LogicException('Settlement must match the completed primary execution.');
        }

        $settlementFailed = in_array($settlement, [JobSettlementState::AcknowledgeFailed, JobSettlementState::RejectFailed], true);
        if ($settlementFailed !== ($failure !== null)) {
            throw new LogicException('A failed settlement must expose its queue failure.');
        }

        if ($failure !== null
            && (($settlement === JobSettlementState::AcknowledgeFailed && $failure->operation() !== QueueOperation::Acknowledge)
                || ($settlement === JobSettlementState::RejectFailed && $failure->operation() !== QueueOperation::Reject))) {
            throw new LogicException('Failed settlement must report the matching queue operation.');
        }

        return new self(
            $execution,
            $failure,
            $settlement,
            $settlementFailed ? ProcessReuseDecision::QuarantineRequired : ProcessReuseDecision::Reusable,
        );
    }

    public function executionOutcome(): ?ExecutionOutcome
    {
        return $this->executionOutcome;
    }

    public function queueFailure(): ?QueueException
    {
        return $this->queueFailure;
    }

    public function settlement(): JobSettlementState
    {
        return $this->settlement;
    }

    public function reuseDecision(): ProcessReuseDecision
    {
        return $this->reuseDecision;
    }

    public function isIdle(): bool
    {
        return $this->executionOutcome === null && $this->queueFailure === null;
    }
}
