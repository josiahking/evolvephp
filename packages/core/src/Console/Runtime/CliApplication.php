<?php

declare(strict_types=1);

namespace Evolve\Core\Console\Runtime;

use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Core\Console\CommandResult;
use Evolve\Core\Console\CommandRunner;
use Evolve\Core\Exception\CommandNotFound;
use Evolve\Core\Execution\ExecutionOutcome;
use LogicException;

/**
 * @experimental
 */
final readonly class CliApplication
{
    public function __construct(
        private CommandRunner $commands,
    ) {}

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments, CommandOutput $output): int
    {
        if ($arguments === []) {
            $output->writeError('No command was specified.');

            return 2;
        }

        /** @var string $commandName */
        $commandName = array_shift($arguments);

        try {
            $outcome = $this->commands->run(
                $commandName,
                new CommandInput($arguments),
                $output,
            );
        } catch (CommandNotFound $exception) {
            $output->writeError($exception->getMessage());

            return 2;
        }

        return $this->exitCodeFrom($outcome);
    }

    private function exitCodeFrom(ExecutionOutcome $outcome): int
    {
        $cleanupThrowable = $outcome->cleanupThrowable();

        if ($cleanupThrowable !== null) {
            throw $cleanupThrowable;
        }

        if ($outcome->primaryFailed()) {
            throw $outcome->primaryThrowableOrFail();
        }

        $primaryResult = $outcome->primaryResult();

        if ($primaryResult instanceof CommandResult) {
            return $primaryResult->exitCode();
        }

        throw new LogicException('Command runner did not return a command result.');
    }
}
