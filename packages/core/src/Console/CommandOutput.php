<?php

declare(strict_types=1);

namespace Evolve\Core\Console;

interface CommandOutput
{
    public function write(string $message): void;

    public function writeError(string $message): void;
}
