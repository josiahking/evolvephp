<?php

declare(strict_types=1);

use Evolve\Core\Console\CommandRegistry;
use Evolve\Core\Console\CommandRunner;
use Evolve\Core\Console\Runtime\CliApplication;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;

$services = new ServiceRegistry();
$services->freeze();

$commands = require dirname(__DIR__) . '/config/commands.php';

return new CliApplication(new CommandRunner(
    new CommandRegistry($commands),
    new ExecutionOrchestrator($services),
));
