<?php

declare(strict_types=1);

namespace Evolve\Storage\Local\Internal;

use Evolve\Storage\Contracts\ReadableObject;
use InvalidArgumentException;
use LogicException;

/**
 * @internal
 */
final class LocalFilesystemReadableObject implements ReadableObject
{
    /** @var resource|null */
    private $stream;

    private bool $closed = false;

    /** @param resource $stream */
    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    public function read(int $maxBytes): ?string
    {
        if ($maxBytes <= 0) {
            throw new InvalidArgumentException('Read size must be positive.');
        }

        if ($this->closed) {
            throw new LogicException('The readable object is closed.');
        }

        if (!is_resource($this->stream)) {
            throw new LocalFilesystemStorageException(\Evolve\Storage\Contracts\StorageOperation::Read);
        }

        $chunk = @fread($this->stream, $maxBytes);

        if ($chunk === false || ($chunk === '' && !feof($this->stream))) {
            throw new LocalFilesystemStorageException(\Evolve\Storage\Contracts\StorageOperation::Read);
        }

        return $chunk === '' ? null : $chunk;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $stream = $this->stream;
        $this->stream = null;
        $this->closed = true;

        if (!is_resource($stream) || !@fclose($stream)) {
            throw new LocalFilesystemStorageException(\Evolve\Storage\Contracts\StorageOperation::Close);
        }
    }

    /** @return array{stream: string} */
    public function __debugInfo(): array
    {
        return ['stream' => '[REDACTED]'];
    }
}
