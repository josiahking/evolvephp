<?php

declare(strict_types=1);

namespace Evolve\Queue\Contracts;

/**
 * Portable broad classification for queue provider failures.
 *
 * @experimental EvolvePHP 2 is pre-beta; this queue contract may change before stable release.
 */
enum QueueFailureCategory: string
{
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case Timeout = 'timeout';
    case Unavailable = 'unavailable';
    case Capacity = 'capacity';
    case Payload = 'payload';
    case Settlement = 'settlement';
    case Transport = 'transport';
    case Unknown = 'unknown';
}
