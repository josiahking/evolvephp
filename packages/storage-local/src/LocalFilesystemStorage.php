<?php

declare(strict_types=1);

namespace Evolve\Storage\Local;

use Evolve\Storage\Contracts\ObjectStorage;
use Evolve\Storage\Contracts\ReadableObject;
use Evolve\Storage\Contracts\StorageKey;
use Evolve\Storage\Contracts\StorageOperation;
use Evolve\Storage\Local\Internal\LocalFilesystemReadableObject;
use Evolve\Storage\Local\Internal\LocalFilesystemStorageException;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

final class LocalFilesystemStorage implements ObjectStorage
{
    private const HEADER_MAGIC = 'EVPHPST1';

    private const RESERVED_DIRECTORY = '.evolvephp-storage';

    private const OBJECTS_DIRECTORY = 'objects';

    private readonly string $rootDirectory;

    public function __construct(
        #[SensitiveParameter]
        string $rootDirectory,
    ) {
        if ($rootDirectory === '' || str_contains($rootDirectory, "\0") || !$this->isAbsolutePath($rootDirectory)) {
            throw new InvalidArgumentException('Storage root must be a non-empty absolute directory path.');
        }

        $canonicalRoot = realpath($rootDirectory);

        if ($canonicalRoot === false || !is_dir($canonicalRoot)) {
            throw new InvalidArgumentException('Storage root must be an existing directory.');
        }

        $this->rootDirectory = $canonicalRoot;
    }

    /** @return array{rootDirectory: string} */
    public function __debugInfo(): array
    {
        return ['rootDirectory' => '[REDACTED]'];
    }

    /** @param iterable<mixed> $chunks */
    public function put(StorageKey $key, iterable $chunks): void
    {
        $operation = StorageOperation::Put;
        $objectPath = $this->objectPath($key);
        $shardDirectory = dirname($objectPath);
        $this->ensureDirectory($shardDirectory, $operation, true);
        $this->validateExistingObject($objectPath, $key, $operation);

        $temporaryPath = null;
        $stream = false;

        try {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $candidate = $shardDirectory . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(16));
                $stream = @fopen($candidate, 'x+b');

                if (is_resource($stream)) {
                    $temporaryPath = $candidate;
                    break;
                }
            }

            if (!is_resource($stream)) {
                throw new LocalFilesystemStorageException($operation);
            }

            $keyBytes = $key->value();
            $this->writeAll($stream, self::HEADER_MAGIC . pack('n', strlen($keyBytes)) . $keyBytes, $operation);

            foreach ($chunks as $chunk) {
                if (!is_string($chunk)) {
                    throw new InvalidArgumentException('Storage chunks must contain only strings.');
                }

                $this->writeAll($stream, $chunk, $operation);
            }

            if (!@fclose($stream)) {
                $stream = false;
                throw new LocalFilesystemStorageException($operation);
            }

            $stream = false;

            if (!@rename($temporaryPath, $objectPath)) {
                $this->assertSafeObjectTarget($objectPath, $operation);

                if (file_exists($objectPath) && !@unlink($objectPath)) {
                    throw new LocalFilesystemStorageException($operation);
                }

                if (!@rename($temporaryPath, $objectPath)) {
                    throw new LocalFilesystemStorageException($operation);
                }
            }

            $temporaryPath = null;
        } catch (Throwable $throwable) {
            if (is_resource($stream)) {
                @fclose($stream);
            }

            if ($temporaryPath !== null) {
                @unlink($temporaryPath);
            }

            throw $throwable;
        }
    }

    public function open(StorageKey $key): ?ReadableObject
    {
        $operation = StorageOperation::Open;
        $objectPath = $this->objectPath($key);

        if (!$this->ensureDirectory(dirname($objectPath), $operation, false)) {
            return null;
        }

        if (!$this->objectExists($objectPath, $operation)) {
            return null;
        }

        $stream = @fopen($objectPath, 'rb');

        if (!is_resource($stream)) {
            throw new LocalFilesystemStorageException($operation);
        }

        try {
            $this->validateHeader($stream, $key, $operation);
        } catch (Throwable $throwable) {
            @fclose($stream);
            throw $throwable;
        }

        return new LocalFilesystemReadableObject($stream);
    }

    public function delete(StorageKey $key): void
    {
        $operation = StorageOperation::Delete;
        $objectPath = $this->objectPath($key);

        if (!$this->ensureDirectory(dirname($objectPath), $operation, false)) {
            return;
        }

        if (!$this->objectExists($objectPath, $operation)) {
            return;
        }

        $stream = @fopen($objectPath, 'rb');

        if (!is_resource($stream)) {
            throw new LocalFilesystemStorageException($operation);
        }

        try {
            $this->validateHeader($stream, $key, $operation);
        } finally {
            @fclose($stream);
        }

        if (!$this->assertSafeObjectTarget($objectPath, $operation) || !@unlink($objectPath)) {
            throw new LocalFilesystemStorageException($operation);
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return str_starts_with($path, '\\\\')
                || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        }

        return str_starts_with($path, '/');
    }

    private function objectPath(StorageKey $key): string
    {
        $digest = hash('sha256', $key->value());

        $relativePath = self::RESERVED_DIRECTORY
            . DIRECTORY_SEPARATOR . 'v1'
            . DIRECTORY_SEPARATOR . self::OBJECTS_DIRECTORY
            . DIRECTORY_SEPARATOR . substr($digest, 0, 2)
            . DIRECTORY_SEPARATOR . substr($digest, 2, 2)
            . DIRECTORY_SEPARATOR . $digest . '.obj';

        return $this->joinPath($this->rootDirectory, $relativePath);
    }

    private function ensureDirectory(string $directory, StorageOperation $operation, bool $create): bool
    {
        $relative = substr($directory, strlen($this->rootDirectory));
        $current = $this->rootDirectory;

        foreach (array_filter(explode(DIRECTORY_SEPARATOR, $relative), static fn(string $part): bool => $part !== '') as $part) {
            $current = $this->joinPath($current, $part);

            if (is_link($current)) {
                throw new LocalFilesystemStorageException($operation);
            }

            if (file_exists($current)) {
                if (!is_dir($current)) {
                    throw new LocalFilesystemStorageException($operation);
                }

                continue;
            }

            if (!$create) {
                return false;
            }

            if (!@mkdir($current) && !is_dir($current)) {
                throw new LocalFilesystemStorageException($operation);
            }
        }

        return true;
    }

    private function joinPath(string $base, string $relative): string
    {
        $base = rtrim($base, DIRECTORY_SEPARATOR);

        if ($base === '' && DIRECTORY_SEPARATOR === '/') {
            return DIRECTORY_SEPARATOR . $relative;
        }

        return $base . DIRECTORY_SEPARATOR . $relative;
    }

    private function objectExists(string $objectPath, StorageOperation $operation): bool
    {
        if (is_link($objectPath)) {
            throw new LocalFilesystemStorageException($operation);
        }

        if (!file_exists($objectPath)) {
            return false;
        }

        if (!is_file($objectPath)) {
            throw new LocalFilesystemStorageException($operation);
        }

        return true;
    }

    private function assertSafeObjectTarget(string $objectPath, StorageOperation $operation): bool
    {
        if (is_link($objectPath)) {
            throw new LocalFilesystemStorageException($operation);
        }

        if (file_exists($objectPath) && !is_file($objectPath)) {
            throw new LocalFilesystemStorageException($operation);
        }

        return true;
    }

    private function validateExistingObject(string $objectPath, StorageKey $key, StorageOperation $operation): void
    {
        if (!$this->objectExists($objectPath, $operation)) {
            return;
        }

        $stream = @fopen($objectPath, 'rb');

        if (!is_resource($stream)) {
            throw new LocalFilesystemStorageException($operation);
        }

        try {
            $this->validateHeader($stream, $key, $operation);
        } finally {
            @fclose($stream);
        }
    }

    /** @param resource $stream */
    private function validateHeader($stream, StorageKey $key, StorageOperation $operation): void
    {
        $header = $this->readExact($stream, 10, $operation);

        if (substr($header, 0, 8) !== self::HEADER_MAGIC) {
            throw new LocalFilesystemStorageException($operation);
        }

        $length = unpack('nlength', substr($header, 8, 2));
        $keyLength = is_array($length) ? $length['length'] : 0;

        if ($keyLength < 1 || $keyLength > 1024) {
            throw new LocalFilesystemStorageException($operation);
        }

        $storedKey = $this->readExact($stream, $keyLength, $operation);

        if (!hash_equals($key->value(), $storedKey)) {
            throw new LocalFilesystemStorageException($operation);
        }
    }

    /** @param resource $stream */
    private function readExact($stream, int $length, StorageOperation $operation): string
    {
        $result = '';

        while (strlen($result) < $length) {
            $chunk = @fread($stream, $length - strlen($result));

            if ($chunk === false || $chunk === '') {
                throw new LocalFilesystemStorageException($operation);
            }

            $result .= $chunk;
        }

        return $result;
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes, StorageOperation $operation): void
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = @fwrite($stream, substr($bytes, $offset));

            if ($written === false || $written === 0) {
                throw new LocalFilesystemStorageException($operation);
            }

            $offset += $written;
        }
    }
}
