<?php

declare(strict_types=1);

namespace Evolve\Queue\Contracts\Exception;

use Evolve\Contracts\Exception\EvolveException;
use Evolve\Queue\Contracts\QueueFailureCategory;
use Evolve\Queue\Contracts\QueueOperation;

/**
 * Portable catch boundary for queue provider and backend failures.
 *
 * Implementations must not expose payloads, metadata, credentials, endpoints, ownership handles,
 * or arbitrary provider metadata through this framework-owned boundary.
 *
 * @experimental EvolvePHP 2 is pre-beta; this queue contract may change before stable release.
 */
interface QueueException extends EvolveException
{
    public function operation(): QueueOperation;

    public function category(): QueueFailureCategory;
}
