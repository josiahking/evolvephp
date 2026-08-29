<?php

declare(strict_types=1);

namespace Evolve\Testing\Console;

use Evolve\Core\Console\CommandOutput;

/**
 * In-memory command output recorder for command tests.
 *
 * @experimental
 */
final class RecordingCommandOutput implements CommandOutput
{
    /**
     * @var list<string>
     */
    private array $lines = [];

    /**
     * @var list<string>
     */
    private array $errorLines = [];

    public function write(string $message): void
    {
        $this->lines[] = $message;
    }

    public function writeError(string $message): void
    {
        $this->errorLines[] = $message;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @return list<string>
     */
    public function errorLines(): array
    {
        return $this->errorLines;
    }
}
