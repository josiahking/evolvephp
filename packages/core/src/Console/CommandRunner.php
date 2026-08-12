<?php

declare(strict_types=1);

namespace Evolve\Core\Console;

use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionOutcome;

final class CommandRunner
{
    public function __construct(private CommandRegistry $commands, private ExecutionOrchestrator $executions) {}

    public function run(string $name, CommandInput $input, CommandOutput $output): ExecutionOutcome
    {
        $command = $this->commands->get($name);

        return $this->executions->execute(
            ExecutionKind::CliCommand,
            static fn(): CommandResult => $command->execute($input, $output),
        );
    }
}
