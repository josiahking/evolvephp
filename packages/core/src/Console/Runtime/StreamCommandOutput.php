<?php

declare(strict_types=1);

namespace Evolve\Core\Console\Runtime;

use Evolve\Core\Console\CommandOutput;
use InvalidArgumentException;
use RuntimeException;

/**
 * @internal
 */
final readonly class StreamCommandOutput implements CommandOutput
{
    /**
     * @param resource $output
     * @param resource $errorOutput
     */
    public function __construct(
        private mixed $output,
        private mixed $errorOutput,
    ) {
        $this->assertWritableStream($output, 'normal output');
        $this->assertWritableStream($errorOutput, 'error output');
    }

    public function write(string $message): void
    {
        $this->writeLine($this->output, $message);
    }

    public function writeError(string $message): void
    {
        $this->writeLine($this->errorOutput, $message);
    }

    /**
     * @param mixed $stream
     */
    private function assertWritableStream(mixed $stream, string $label): void
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException(sprintf('The %s stream must be a PHP stream resource.', $label));
        }

        $metadata = stream_get_meta_data($stream);
        $mode = $metadata['mode'];

        if (strpbrk($mode, 'waxc+') === false) {
            throw new InvalidArgumentException(sprintf('The %s stream must be writable.', $label));
        }
    }

    /**
     * @param resource $stream
     */
    private function writeLine(mixed $stream, string $message): void
    {
        $line = $message . PHP_EOL;
        $bytes = fwrite($stream, $line);

        if ($bytes !== strlen($line)) {
            throw new RuntimeException('Unable to write command output.');
        }
    }
}
