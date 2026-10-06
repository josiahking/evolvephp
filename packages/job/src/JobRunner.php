<?php

declare(strict_types=1);

namespace Evolve\Job;

use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionContextValues;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionOutcome;
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
        private ?JobExecutionContext $executionContext = null,
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

        $execute = fn(): ExecutionOutcome => $this->orchestrator->execute(
            ExecutionKind::QueueMessage,
            static fn(ExecutionContext $context, ExecutionScope $scope): mixed => $handler($message, $context, $scope),
            $values,
        );

        if ($this->executionContext === null) {
            try {
                $execution = $execute();
            } catch (ExecutionStartFailed $failure) {
                $this->quarantined = true;
                throw $failure;
            }
        } else {
            $state = new class {
                public bool $active = true;
                public bool $invoked = false;
                public bool $secondAttempted = false;
                public ?ExecutionOutcome $produced = null;
                public ?ExecutionStartFailed $coreStartFailure = null;
            };
            $executeOnce = function () use ($execute, $state): ExecutionOutcome {
                if (!$state->active) {
                    $this->quarantined = true;
                    throw new \LogicException('Job execution context invoked Core execution outside its wrapper.');
                }
                if ($state->invoked) {
                    $state->secondAttempted = true;
                    $this->quarantined = true;
                    throw new \LogicException('Job execution context attempted Core execution more than once.');
                }

                $state->invoked = true;

                try {
                    return $state->produced = $execute();
                } catch (ExecutionStartFailed $failure) {
                    $state->coreStartFailure = $failure;
                    throw $failure;
                }
            };

            try {
                $execution = $this->executionContext->run($message, $executeOnce);

                if ($state->coreStartFailure !== null) {
                    throw $state->coreStartFailure;
                }
                if (!$state->invoked || $state->secondAttempted || $execution !== $state->produced) {
                    throw new \LogicException('Job execution context must return its single Core execution outcome.');
                }
            } catch (\Throwable $failure) {
                $this->quarantined = true;
                throw $failure;
            } finally {
                $state->active = false;
            }
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
