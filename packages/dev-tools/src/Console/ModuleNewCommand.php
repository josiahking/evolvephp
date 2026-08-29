<?php

declare(strict_types=1);

namespace Evolve\DevTools\Console;

use Evolve\Core\Console\Command;
use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Core\Console\CommandResult;
use RuntimeException;

/**
 * @experimental
 */
final readonly class ModuleNewCommand implements Command
{
    private ComponentScaffoldGenerator $generator;

    public function __construct(string $projectRoot)
    {
        $this->generator = new ComponentScaffoldGenerator($projectRoot);
    }

    public function name(): string
    {
        return 'module:new';
    }

    public function description(): string
    {
        return 'Create an application module scaffold.';
    }

    public function execute(CommandInput $input, CommandOutput $output): CommandResult
    {
        $tokens = $input->tokens();

        if (count($tokens) !== 1 || ! ComponentScaffoldGenerator::isValidName($tokens[0])) {
            $output->writeError('Usage: module:new <StudlyName>');

            return new CommandResult(2);
        }

        try {
            $scaffold = $this->generator->generateModule($tokens[0]);
        } catch (RuntimeException $exception) {
            $output->writeError($exception->getMessage());

            return new CommandResult(1);
        }

        $output->write('Created module ' . $scaffold['identifier'] . '.');

        foreach ($scaffold['paths'] as $path) {
            $output->write($path);
        }

        return new CommandResult(0);
    }
}
