<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Console\Runtime;

use Closure;
use Evolve\Core\Console\Command;
use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Core\Console\CommandRegistry;
use Evolve\Core\Console\CommandResult;
use Evolve\Core\Console\CommandRunner;
use Evolve\Core\Console\Runtime\CliApplication;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Core\Instrumentation\ObservationSink;
use Evolve\Core\Instrumentation\ObservationType;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class CliApplicationTest extends TestCase
{
    public function testNoCommandReturnsUsageErrorWithoutExecution(): void
    {
        $observations = new CollectingObservationSink();
        $output = new RecordingCommandOutput();

        $exitCode = $this->application([], $observations)->run([], $output);

        self::assertSame(2, $exitCode);
        self::assertSame([], $output->lines());
        self::assertSame(['No command was specified.'], $output->errorLines());
        self::assertSame([], $observations->observations);
    }

    public function testUnknownCommandReturnsUsageErrorWithoutExecution(): void
    {
        $observations = new CollectingObservationSink();
        $output = new RecordingCommandOutput();

        $exitCode = $this->application([], $observations)->run(['missing'], $output);

        self::assertSame(2, $exitCode);
        self::assertSame([], $output->lines());
        self::assertSame(['Command "missing" was not found.'], $output->errorLines());
        self::assertSame([], $observations->observations);
    }

    public function testKnownCommandReceivesOnlyTokensAfterCommandName(): void
    {
        $receivedTokens = [];
        $output = new RecordingCommandOutput();

        $exitCode = $this->application([
            new CallbackCommand(
                'doctor',
                static function (CommandInput $input, CommandOutput $_output) use (&$receivedTokens): CommandResult {
                    $receivedTokens = $input->tokens();

                    return new CommandResult(0);
                },
            ),
        ])->run(['doctor', 'foo', 'bar'], $output);

        self::assertSame(0, $exitCode);
        self::assertSame(['foo', 'bar'], $receivedTokens);
    }

    public function testKnownSuccessfulCommandResultReturnsZero(): void
    {
        $exitCode = $this->application([
            new CallbackCommand(
                'doctor',
                static fn(CommandInput $_input, CommandOutput $_output): CommandResult => new CommandResult(0),
            ),
        ])->run(['doctor'], new RecordingCommandOutput());

        self::assertSame(0, $exitCode);
    }

    public function testKnownNonZeroCommandResultIsReturnedUnchanged(): void
    {
        $exitCode = $this->application([
            new CallbackCommand(
                'doctor',
                static fn(CommandInput $_input, CommandOutput $_output): CommandResult => new CommandResult(1),
            ),
        ])->run(['doctor'], new RecordingCommandOutput());

        self::assertSame(1, $exitCode);
    }

    public function testNonZeroCommandResultRemainsSuccessfulFrameworkExecution(): void
    {
        $observations = new CollectingObservationSink();

        $exitCode = $this->application([
            new CallbackCommand(
                'doctor',
                static fn(CommandInput $_input, CommandOutput $_output): CommandResult => new CommandResult(1),
            ),
        ], $observations)->run(['doctor'], new RecordingCommandOutput());

        self::assertSame(1, $exitCode);
        $this->assertObservedCliCommandLifecycle($observations);

        $handlerCompleted = null;

        foreach ($observations->observations as $observation) {
            if ($observation->type() === ObservationType::HandlerCompleted) {
                $handlerCompleted = $observation;

                break;
            }
        }

        self::assertNotNull($handlerCompleted);
        self::assertSame(
            ObservationOutcome::Succeeded,
            $handlerCompleted->outcome(),
        );
    }

    public function testPrimaryCommandThrowablePropagatesWhenCleanupSucceeds(): void
    {
        $throwable = new RuntimeException('primary failure');

        $this->expectExceptionObject($throwable);

        $this->application([
            new CallbackCommand(
                'doctor',
                static function (
                    CommandInput $_input,
                    CommandOutput $_output,
                ) use ($throwable): CommandResult {
                    throw $throwable;
                },
            ),
        ])->run(['doctor'], new RecordingCommandOutput());
    }

    public function testCleanupFailureIsNotHiddenBehindCommandResult(): void
    {
        $throwable = new RuntimeException('cleanup failure');

        $outcome = ExecutionOutcome::succeeded(
            ExecutionIdentifier::generate(),
            ExecutionKind::CliCommand,
            new CommandResult(0),
            $throwable,
        );

        $this->expectExceptionObject($throwable);

        $this->exitCodeFrom($outcome);
    }

    public function testCleanupFailureTakesPrecedenceOverPrimaryFailure(): void
    {
        $primaryThrowable = new RuntimeException('primary failure');
        $cleanupThrowable = new RuntimeException('cleanup failure');

        $outcome = ExecutionOutcome::failed(
            ExecutionIdentifier::generate(),
            ExecutionKind::CliCommand,
            $primaryThrowable,
            $cleanupThrowable,
        );

        $this->expectExceptionObject($cleanupThrowable);

        $this->exitCodeFrom($outcome);
    }

    public function testExecutionRemainsCliCommandThroughExistingRunnerLifecycle(): void
    {
        $observations = new CollectingObservationSink();

        $this->application([
            new CallbackCommand(
                'doctor',
                static fn(CommandInput $_input, CommandOutput $_output): CommandResult => new CommandResult(0),
            ),
        ], $observations)->run(['doctor'], new RecordingCommandOutput());

        $this->assertObservedCliCommandLifecycle($observations);
    }

    /**
     * @param list<Command> $commands
     */
    private function application(
        array $commands,
        ?ObservationSink $sink = null,
    ): CliApplication {
        $registry = new ServiceRegistry();
        $registry->freeze();

        return new CliApplication(new CommandRunner(
            new CommandRegistry($commands),
            new ExecutionOrchestrator($registry, $sink),
        ));
    }

    private function exitCodeFrom(ExecutionOutcome $outcome): int
    {
        $application = $this->application([]);
        $method = new ReflectionMethod(CliApplication::class, 'exitCodeFrom');

        $result = $method->invoke($application, $outcome);

        self::assertIsInt($result);

        return $result;
    }

    private function assertObservedCliCommandLifecycle(
        CollectingObservationSink $observations,
    ): void {
        self::assertNotSame([], $observations->observations);

        foreach ($observations->observations as $observation) {
            self::assertSame(
                ExecutionKind::CliCommand,
                $observation->kind(),
            );
        }
    }
}

final readonly class CallbackCommand implements Command
{
    /**
     * @param Closure(CommandInput, CommandOutput): CommandResult $callback
     */
    public function __construct(
        private string $name,
        private Closure $callback,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return 'Test command.';
    }

    public function execute(
        CommandInput $input,
        CommandOutput $output,
    ): CommandResult {
        return ($this->callback)($input, $output);
    }
}

final class CollectingObservationSink implements ObservationSink
{
    /** @var list<Observation> */
    public array $observations = [];

    public function observe(Observation $observation): void
    {
        $this->observations[] = $observation;
    }
}

final class RecordingCommandOutput implements CommandOutput
{
    /** @var list<string> */
    private array $lines = [];

    /** @var list<string> */
    private array $errorLines = [];

    public function write(string $message): void
    {
        $this->lines[] = $message;
    }

    public function writeError(string $message): void
    {
        $this->errorLines[] = $message;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @return list<string>
     */
    public function errorLines(): array
    {
        return $this->errorLines;
    }
}
