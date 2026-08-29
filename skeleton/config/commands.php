<?php

declare(strict_types=1);

use Evolve\Core\Doctor\Console\DoctorCommand;
use Evolve\Core\Doctor\DoctorRunner;
use Evolve\Core\Doctor\Project\ComposerRequiredExtensionsCheck;
use Evolve\Core\Doctor\Runtime\PhpVersionCheck;
use Evolve\DevTools\Console\ModuleNewCommand;
use Evolve\DevTools\Console\PluginNewCommand;
use Evolve\Http\Routing\Console\RouteListCommand;

$routes = require __DIR__ . '/routes.php';

$commands = [
    new DoctorCommand(new DoctorRunner([
        new PhpVersionCheck(),
        new ComposerRequiredExtensionsCheck(__DIR__ . '/../composer.json'),
    ])),
    new RouteListCommand($routes),
];

if (class_exists(ModuleNewCommand::class)) {
    $commands[] = new ModuleNewCommand(dirname(__DIR__));
}

if (class_exists(PluginNewCommand::class)) {
    $commands[] = new PluginNewCommand(dirname(__DIR__));
}

return $commands;
