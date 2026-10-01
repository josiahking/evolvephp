<?php

declare(strict_types=1);

namespace Evolve\Secret\Contracts;

/**
 * Broad portable category for secret provider failures.
 *
 * @experimental EvolvePHP 2 is pre-beta; this secret contract may change before stable release.
 */
enum SecretFailureCategory: string
{
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case Timeout = 'timeout';
    case Unavailable = 'unavailable';
    case Transport = 'transport';
    case Unknown = 'unknown';
}
