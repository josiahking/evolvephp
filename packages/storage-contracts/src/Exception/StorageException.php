<?php

declare(strict_types=1);

namespace Evolve\Storage\Contracts\Exception;

use Evolve\Contracts\Exception\EvolveException;
use Evolve\Storage\Contracts\StorageFailureCategory;
use Evolve\Storage\Contracts\StorageOperation;

/**
 * Portable catch boundary for storage provider and backend failures.
 *
 * Implementations must not expose keys, object bytes, credentials, endpoints, provider handles,
 * or arbitrary provider metadata through this framework-owned boundary.
 *
 * @experimental EvolvePHP 2 is pre-beta; this storage contract may change before stable release.
 */
interface StorageException extends EvolveException
{
    public function operation(): StorageOperation;

    public function category(): StorageFailureCategory;
}
