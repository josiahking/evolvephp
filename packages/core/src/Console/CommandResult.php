<?php

declare(strict_types=1);

namespace Evolve\Core\Console;

use InvalidArgumentException;

final readonly class CommandResult
{
    public function __construct(private int $exitCode)
    {
        if ($this->exitCode < 0) {
            throw new InvalidArgumentException('Command result exit code must be a non-negative integer.');
        }
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
