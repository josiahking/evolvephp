<?php

declare(strict_types=1);

namespace Evolve\Job;

use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionContextValues;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Queue\Contracts\Exception\QueueException;
use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueueReceiver;

/** @experimental EvolvePHP 2 is pre-beta; this job API may change before stable release. */
final class JobRunner
{
    private bool $quarantined = false;

    public function __construct(
        private QueueReceiver $receiver,
        private ExecutionOrchestrator $orchestrator,
    ) {}

    /** @param callable(MessageEnvelope, ExecutionContext, ExecutionScope): mixed $handler */
    public function runOnce(QueueName $queue, callable $handler, ?ExecutionContextValues $values = null): JobRunOutcome
    {
        if ($this->quarantined) {
            throw new ExecutionStartFailed('Job runner is quarantined and cannot accept more work.');
        }

        try {
            $delivery = $this->receiver->receive($queue);
        } catch (QueueException $failure) {
            return JobRunOutcome::receiveFailed($failure);
        }

        if ($delivery === null) {
            return JobRunOutcome::idle();
        }

        $message = $delivery->message();

        try {
            $execution = $this->orchestrator->execute(
                ExecutionKind::QueueMessage,
                static fn(ExecutionContext $context, ExecutionScope $scope): mixed => $handler($message, $context, $scope),
                $values,
            );
        } catch (ExecutionStartFailed $failure) {
            $this->quarantined = true;
            throw $failure;
        }

        if ($execution->requiresQuarantine()) {
            $this->quarantined = true;
            return JobRunOutcome::executed($execution, JobSettlementState::NotAttempted);
        }

        if ($execution->primarySucceeded()) {
            try {
                $delivery->acknowledge();
            } catch (QueueException $failure) {
                $this->quarantined = true;
                return JobRunOutcome::executed($execution, JobSettlementState::AcknowledgeFailed, $failure);
            }

            return JobRunOutcome::executed($execution, JobSettlementState::Acknowledged);
        }

        try {
            $delivery->reject();
        } catch (QueueException $failure) {
            $this->quarantined = true;
            return JobRunOutcome::executed($execution, JobSettlementState::RejectFailed, $failure);
        }

        return JobRunOutcome::executed($execution, JobSettlementState::Rejected);
    }
}
