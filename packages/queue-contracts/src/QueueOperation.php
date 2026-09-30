<?php

declare(strict_types=1);

namespace Evolve\Queue\Contracts;

/**
 * Queue operation associated with a provider failure.
 *
 * @experimental EvolvePHP 2 is pre-beta; this queue contract may change before stable release.
 */
enum QueueOperation: string
{
    case Publish = 'publish';
    case Receive = 'receive';
    case Acknowledge = 'acknowledge';
    case Reject = 'reject';
}
