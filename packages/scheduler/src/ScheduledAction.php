<?php

declare(strict_types=1);

namespace Evolve\Scheduler;

use Closure;
use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Core\Console\CommandRegistry;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueuePublisher;

/** @experimental This API may change before stable release. */
final readonly class ScheduledAction
{
    /** @param Closure(ExecutionContext, ExecutionScope): mixed $operation */
    private function __construct(private ScheduledActionKind $kind, private Closure $operation) {}

    /** @param callable(ExecutionContext, ExecutionScope): mixed $callback */
    public static function callback(callable $callback): self
    {
        return new self(ScheduledActionKind::Callback, Closure::fromCallable($callback));
    }

    public static function command(CommandRegistry $registry, string $name, CommandInput $input, CommandOutput $output): self
    {
        return new self(ScheduledActionKind::Command, static fn(ExecutionContext $context, ExecutionScope $scope): mixed => $registry->get($name)->execute($input, $output));
    }

    public static function queueJob(QueuePublisher $publisher, QueueName $queue, MessageEnvelope $message): self
    {
        return new self(ScheduledActionKind::QueueJob, static function (ExecutionContext $context, ExecutionScope $scope) use ($publisher, $queue, $message): void {
            $publisher->publish($queue, $message);
        });
    }

    public function kind(): ScheduledActionKind
    {
        return $this->kind;
    }

    public function invoke(ExecutionContext $context, ExecutionScope $scope): mixed
    {
        return ($this->operation)($context, $scope);
    }
}
