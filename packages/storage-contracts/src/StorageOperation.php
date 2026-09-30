<?php

declare(strict_types=1);

namespace Evolve\Storage\Contracts;

/**
 * Storage operation associated with a provider failure.
 *
 * @experimental EvolvePHP 2 is pre-beta; this storage contract may change before stable release.
 */
enum StorageOperation: string
{
    case Put = 'put';
    case Open = 'open';
    case Read = 'read';
    case Delete = 'delete';
    case Close = 'close';
}
