<?php

declare(strict_types=1);

namespace Evolve\Scheduler\Tests\Unit;

use Evolve\Core\Console\Command;
use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Core\Console\CommandRegistry;
use Evolve\Core\Console\CommandResult;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueuePublisher;
use Evolve\Scheduler\ScheduledAction;
use Evolve\Scheduler\ScheduledActionKind;
use PHPUnit\Framework\TestCase;

final class ScheduledActionTest extends TestCase
{
    public function test_callback_preserves_result_and_scoped_arguments(): void
    {
        $result = new \stdClass();
        $context = $this->context();
        $scope = $this->createStub(ExecutionScope::class);
        $action = ScheduledAction::callback(static function ($receivedContext, $receivedScope) use ($context, $scope, $result): object {
            self::assertSame($context, $receivedContext);
            self::assertSame($scope, $receivedScope);
            return $result;
        });
        self::assertSame(ScheduledActionKind::Callback, $action->kind());
        self::assertSame($result, $action->invoke($context, $scope));
    }

    public function test_command_executes_directly_and_returns_command_result(): void
    {
        $result = new CommandResult(0);
        $input = new CommandInput(['--once']);
        $output = $this->createStub(CommandOutput::class);
        $command = new class ($result, $input, $output) implements Command {
            public function __construct(private CommandResult $result, private CommandInput $input, private CommandOutput $output) {}
            public function name(): string
            {
                return 'sync:once';
            }
            public function description(): string
            {
                return 'test';
            }
            public function execute(CommandInput $input, CommandOutput $output): CommandResult
            {
                TestCase::assertSame($this->input, $input);
                TestCase::assertSame($this->output, $output);
                return $this->result;
            }
        };
        $action = ScheduledAction::command(new CommandRegistry([$command]), 'sync:once', $input, $output);
        self::assertSame(ScheduledActionKind::Command, $action->kind());
        self::assertSame($result, $action->invoke($this->context(), $this->createStub(ExecutionScope::class)));
    }

    public function test_queue_action_publishes_exactly_once(): void
    {
        $queue = new QueueName('jobs');
        $message = new MessageEnvelope('opaque');
        $publisher = $this->createMock(QueuePublisher::class);
        $publisher->expects(self::once())->method('publish')->with($queue, $message);
        $action = ScheduledAction::queueJob($publisher, $queue, $message);
        self::assertSame(ScheduledActionKind::QueueJob, $action->kind());
        self::assertNull($action->invoke($this->context(), $this->createStub(ExecutionScope::class)));
    }

    private function context(): ExecutionContext
    {
        return new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::ScheduledJob);
    }
}
