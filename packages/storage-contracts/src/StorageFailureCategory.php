<?php

declare(strict_types=1);

namespace Evolve\Storage\Contracts;

/**
 * Broad portable category for storage provider failures.
 *
 * @experimental EvolvePHP 2 is pre-beta; this storage contract may change before stable release.
 */
enum StorageFailureCategory: string
{
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case Timeout = 'timeout';
    case Unavailable = 'unavailable';
    case Capacity = 'capacity';
    case Transport = 'transport';
    case Unknown = 'unknown';
}
