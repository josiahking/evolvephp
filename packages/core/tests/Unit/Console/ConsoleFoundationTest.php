<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Console;

use Evolve\Core\Console\Command;
use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Core\Console\CommandRegistry;
use Evolve\Core\Console\CommandResult;
use Evolve\Core\Console\CommandRunner;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\CommandNotFound;
use Evolve\Core\Exception\InvalidCommandDefinition;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ProcessReuseDecision;
use Evolve\Core\Instrumentation\InstrumentationFailure;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Core\Instrumentation\ObservationSink;
use Evolve\Core\Instrumentation\ObservationType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class ConsoleFoundationTest extends TestCase
{
    public function test_command_input_accepts_empty_and_preserves_ordered_string_tokens(): void
    {
        self::assertSame([], (new CommandInput())->tokens());

        $tokens = ['--dry-run', 'module-name', 'value with spaces'];
        $input = new CommandInput($tokens);
        $tokens[] = 'mutated';

        self::assertSame(['--dry-run', 'module-name', 'value with spaces'], $input->tokens());
    }

    public function test_command_input_rejects_non_list_or_non_string_tokens(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CommandInput(['first' => 'doctor']);
    }

    public function test_command_input_rejects_non_string_token_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CommandInput(['doctor', 1]);
    }

    public function test_command_result_exposes_exit_status_without_mutable_state(): void
    {
        $success = new CommandResult(0);
        $failureStatus = new CommandResult(2);

        self::assertSame(0, $success->exitCode());
        self::assertTrue($success->successful());
        self::assertSame(2, $failureStatus->exitCode());
        self::assertFalse($failureStatus->successful());

        $api = new ReflectionClass(CommandResult::class);
        foreach ($api->getProperties() as $property) {
            self::assertTrue($property->isPrivate(), CommandResult::class . ' must not expose mutable public state.');
        }
    }

    public function test_command_result_rejects_negative_exit_codes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CommandResult(-1);
    }

    public function test_registry_lookup_is_exact_deterministic_and_case_sensitive(): void
    {
        $doctor = new RecordingCommand('doctor', 'Inspect the installation.', new CommandResult(0));
        $routeList = new RecordingCommand('route:list', 'List routes.', new CommandResult(0));
        $registry = new CommandRegistry([$doctor, $routeList]);

        self::assertTrue($registry->has('doctor'));
        self::assertFalse($registry->has('Doctor'));
        self::assertSame($doctor, $registry->get('doctor'));
        self::assertSame($routeList, $registry->get('route:list'));
        self::assertSame([$doctor, $routeList], $registry->all());
        self::assertSame(0, $doctor->executions);
    }

    public function test_registry_rejects_unknown_invalid_duplicate_or_normalized_names(): void
    {
        $this->expectException(CommandNotFound::class);
        (new CommandRegistry([]))->get('missing');
    }

    public function test_registry_rejects_invalid_command_names(): void
    {
        foreach (['', ' doctor', 'doctor ', "doctor\nrun", "doctor\trun", 'doctor run'] as $name) {
            try {
                new CommandRegistry([new RecordingCommand($name, 'Invalid.', new CommandResult(0))]);
                self::fail('Expected invalid command name to be rejected: ' . str_replace(["\n", "\t"], ['\\n', '\\t'], $name));
            } catch (Throwable $exception) {
                self::assertInstanceOf(InvalidCommandDefinition::class, $exception);
            }
        }
    }

    public function test_registry_rejects_duplicate_command_names(): void
    {
        $this->expectException(InvalidCommandDefinition::class);
        new CommandRegistry([
            new RecordingCommand('doctor', 'First.', new CommandResult(0)),
            new RecordingCommand('doctor', 'Second.', new CommandResult(0)),
        ]);
    }

    public function test_registry_does_not_trim_lowercase_or_alias_names(): void
    {
        $registry = new CommandRegistry([
            new RecordingCommand('doctor', 'Lowercase.', new CommandResult(0)),
            new RecordingCommand('Doctor', 'Uppercase.', new CommandResult(0)),
        ]);

        self::assertTrue($registry->has('doctor'));
        self::assertTrue($registry->has('Doctor'));
        self::assertFalse($registry->has('DOCTOR'));
        self::assertSame('doctor', $registry->get('doctor')->name());
        self::assertSame('Doctor', $registry->get('Doctor')->name());
    }

    public function test_runner_dispatches_resolved_command_through_cli_execution_lifecycle(): void
    {
        $input = new CommandInput(['--check']);
        $output = new InMemoryCommandOutput();
        $result = new CommandResult(0);
        $command = new RecordingCommand('doctor', 'Inspect the installation.', $result);
        $sink = new CollectingObservationSink();

        $outcome = (new CommandRunner(
            new CommandRegistry([$command]),
            new ExecutionOrchestrator($this->frozenRegistry(), $sink),
        ))->run('doctor', $input, $output);

        self::assertSame(1, $command->executions);
        self::assertSame($input, $command->receivedInput);
        self::assertSame($output, $command->receivedOutput);
        self::assertTrue($outcome->primarySucceeded());
        self::assertSame($result, $outcome->primaryResult());
        self::assertSame(ExecutionKind::CliCommand, $outcome->kind());
        self::assertSame(ProcessReuseDecision::Reusable, $outcome->reuseDecision());
        self::assertSame(
            [
                ObservationType::ExecutionStarted,
                ObservationType::HandlerCompleted,
                ObservationType::ScopeCloseStarted,
                ObservationType::ScopeCloseCompleted,
                ObservationType::ExecutionCompleted,
            ],
            $sink->types(),
        );
        foreach ($sink->observations as $observation) {
            self::assertSame(ExecutionKind::CliCommand, $observation->kind());
            self::assertTrue($observation->identifier()->equals($outcome->identifier()));
        }
        self::assertSame(ObservationOutcome::Succeeded, $sink->observations[1]->outcome());
    }

    public function test_non_zero_command_result_remains_successful_framework_execution(): void
    {
        $result = new CommandResult(7);
        $outcome = (new CommandRunner(
            new CommandRegistry([new RecordingCommand('doctor', 'Inspect.', $result)]),
            new ExecutionOrchestrator($this->frozenRegistry()),
        ))->run('doctor', new CommandInput(), new InMemoryCommandOutput());

        self::assertTrue($outcome->primarySucceeded());
        self::assertSame($result, $outcome->primaryResult());
        self::assertFalse($result->successful());
        self::assertNull($outcome->primaryThrowable());
        self::assertSame(ExecutionKind::CliCommand, $outcome->kind());
        self::assertSame(ProcessReuseDecision::Reusable, $outcome->reuseDecision());
    }

    public function test_command_throwable_remains_primary_execution_throwable_after_cleanup(): void
    {
        $failure = new RuntimeException('command failed');
        $sink = new CollectingObservationSink();

        $outcome = (new CommandRunner(
            new CommandRegistry([new ThrowingCommand('doctor', 'Inspect.', $failure)]),
            new ExecutionOrchestrator($this->frozenRegistry(), $sink),
        ))->run('doctor', new CommandInput(), new InMemoryCommandOutput());

        self::assertTrue($outcome->primaryFailed());
        self::assertSame($failure, $outcome->primaryThrowable());
        self::assertFalse($outcome->cleanupFailed());
        self::assertSame(ProcessReuseDecision::Reusable, $outcome->reuseDecision());
        self::assertSame(ExecutionKind::CliCommand, $outcome->kind());
        self::assertSame(ObservationOutcome::Failed, $sink->observations[1]->outcome());
        self::assertSame(RuntimeException::class, $sink->observations[1]->errorType());
        self::assertSame(ObservationOutcome::Succeeded, $sink->observations[3]->outcome());
    }

    public function test_unknown_command_fails_before_execution_starts(): void
    {
        $sink = new CollectingObservationSink();

        try {
            (new CommandRunner(
                new CommandRegistry([]),
                new ExecutionOrchestrator($this->frozenRegistry(), $sink),
            ))->run('missing', new CommandInput(), new InMemoryCommandOutput());
            self::fail('Expected unknown command to fail before execution.');
        } catch (CommandNotFound) {
            self::assertSame([], $sink->observations);
        }
    }

    public function test_sequential_command_runs_receive_distinct_identifiers_and_do_not_cross_input_output_state(): void
    {
        $command = new RecordingCommand('doctor', 'Inspect.', new CommandResult(0));
        $runner = new CommandRunner(
            new CommandRegistry([$command]),
            new ExecutionOrchestrator($this->frozenRegistry()),
        );

        $firstInput = new CommandInput(['first']);
        $firstOutput = new InMemoryCommandOutput();
        $first = $runner->run('doctor', $firstInput, $firstOutput);

        $secondInput = new CommandInput(['second']);
        $secondOutput = new InMemoryCommandOutput();
        $second = $runner->run('doctor', $secondInput, $secondOutput);

        self::assertSame(2, $command->executions);
        self::assertNotSame($first->identifier()->value(), $second->identifier()->value());
        self::assertSame([[$firstInput, $firstOutput], [$secondInput, $secondOutput]], $command->calls);
        self::assertSame(['first'], $command->calls[0][0]->tokens());
        self::assertSame(['second'], $command->calls[1][0]->tokens());
    }

    public function test_instrumentation_failure_stays_separate_from_command_result_and_reuse(): void
    {
        $result = new CommandResult(0);
        $outcome = (new CommandRunner(
            new CommandRegistry([new RecordingCommand('doctor', 'Inspect.', $result)]),
            new ExecutionOrchestrator($this->frozenRegistry(), new ThrowingObservationSink()),
        ))->run('doctor', new CommandInput(), new InMemoryCommandOutput());

        self::assertTrue($outcome->primarySucceeded());
        self::assertSame($result, $outcome->primaryResult());
        self::assertSame(ProcessReuseDecision::Reusable, $outcome->reuseDecision());
        self::assertTrue($outcome->instrumentationFailed());
        self::assertContainsOnlyInstancesOf(InstrumentationFailure::class, $outcome->instrumentationFailures());
    }

    private function frozenRegistry(): ServiceRegistry
    {
        $registry = new ServiceRegistry();
        $registry->freeze();

        return $registry;
    }
}

final class RecordingCommand implements Command
{
    public int $executions = 0;

    public ?CommandInput $receivedInput = null;

    public ?CommandOutput $receivedOutput = null;

    /**
     * @var list<array{CommandInput, CommandOutput}>
     */
    public array $calls = [];

    public function __construct(private string $name, private string $description, private CommandResult $result) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function execute(CommandInput $input, CommandOutput $output): CommandResult
    {
        ++$this->executions;
        $this->receivedInput = $input;
        $this->receivedOutput = $output;
        $this->calls[] = [$input, $output];

        return $this->result;
    }
}

final class ThrowingCommand implements Command
{
    public function __construct(private string $name, private string $description, private RuntimeException $throwable) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function execute(CommandInput $input, CommandOutput $output): CommandResult
    {
        throw $this->throwable;
    }
}

final class InMemoryCommandOutput implements CommandOutput
{
    /**
     * @var list<string>
     */
    public array $messages = [];

    /**
     * @var list<string>
     */
    public array $errorMessages = [];

    public function write(string $message): void
    {
        $this->messages[] = $message;
    }

    public function writeError(string $message): void
    {
        $this->errorMessages[] = $message;
    }
}

final class CollectingObservationSink implements ObservationSink
{
    /**
     * @var list<Observation>
     */
    public array $observations = [];

    public function observe(Observation $observation): void
    {
        $this->observations[] = $observation;
    }

    /**
     * @return list<ObservationType>
     */
    public function types(): array
    {
        return array_map(static fn(Observation $observation): ObservationType => $observation->type(), $this->observations);
    }
}

final class ThrowingObservationSink implements ObservationSink
{
    public function observe(Observation $observation): void
    {
        throw new RuntimeException('sink failed');
    }
}
