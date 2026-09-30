<?php

declare(strict_types=1);

namespace Evolve\Storage\Local\Internal;

use Evolve\Storage\Contracts\Exception\StorageException;
use Evolve\Storage\Contracts\StorageFailureCategory;
use Evolve\Storage\Contracts\StorageOperation;
use RuntimeException;

/**
 * @internal
 */
final class LocalFilesystemStorageException extends RuntimeException implements StorageException
{
    public function __construct(
        private readonly StorageOperation $storageOperation,
    ) {
        parent::__construct('Local filesystem storage operation failed.');
    }

    public function operation(): StorageOperation
    {
        return $this->storageOperation;
    }

    public function category(): StorageFailureCategory
    {
        return StorageFailureCategory::Unknown;
    }
}
