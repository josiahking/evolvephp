<?php

declare(strict_types=1);

namespace Evolve\Http\Routing\Console;

use Evolve\Core\Console\Command;
use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Core\Console\CommandResult;
use Evolve\Http\Routing\RouteCollection;

final readonly class RouteListCommand implements Command
{
    public function __construct(private RouteCollection $routes) {}

    public function name(): string
    {
        return 'route:list';
    }

    public function description(): string
    {
        return 'List configured HTTP routes.';
    }

    public function execute(CommandInput $input, CommandOutput $output): CommandResult
    {
        if ($input->tokens() !== []) {
            $output->writeError('The route:list command does not accept arguments or options.');

            return new CommandResult(2);
        }

        $routes = $this->routes->all();

        if ($routes === []) {
            $output->write('No routes are configured.');

            return new CommandResult(0);
        }

        foreach ($routes as $route) {
            $output->write(implode('|', $route->methods()) . ' ' . $route->path());
        }

        return new CommandResult(0);
    }
}
