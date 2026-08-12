<?php

declare(strict_types=1);

namespace Evolve\Core\Console;

interface Command
{
    public function name(): string;

    public function description(): string;

    public function execute(CommandInput $input, CommandOutput $output): CommandResult;
}
