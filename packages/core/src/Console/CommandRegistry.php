<?php

declare(strict_types=1);

namespace Evolve\Core\Console;

use Evolve\Core\Exception\CommandNotFound;
use Evolve\Core\Exception\InvalidCommandDefinition;

final class CommandRegistry
{
    /**
     * @var array<string, Command>
     */
    private array $commands = [];

    /**
     * @param iterable<Command> $commands
     */
    public function __construct(iterable $commands)
    {
        foreach ($commands as $command) {
            $name = $command->name();
            self::assertValidName($name);

            if (array_key_exists($name, $this->commands)) {
                throw new InvalidCommandDefinition('Command name "' . $name . '" is already registered.');
            }

            $this->commands[$name] = $command;
        }
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->commands);
    }

    public function get(string $name): Command
    {
        if (! array_key_exists($name, $this->commands)) {
            throw CommandNotFound::forName($name);
        }

        return $this->commands[$name];
    }

    /**
     * @return list<Command>
     */
    public function all(): array
    {
        return array_values($this->commands);
    }

    private static function assertValidName(string $name): void
    {
        if ($name === '') {
            throw new InvalidCommandDefinition('Command name must not be empty.');
        }

        if (preg_match('/[\s\x00-\x1F\x7F]/', $name) === 1) {
            throw new InvalidCommandDefinition('Command name must not contain whitespace or control characters.');
        }
    }
}
