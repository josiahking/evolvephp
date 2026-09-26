<?php

declare(strict_types=1);

namespace Evolve\Database\Contracts;

/**
 * Vendor-neutral database failure classification.
 *
 * @experimental EvolvePHP 2 is pre-beta; this database contract may change before stable release.
 */
enum DatabaseFailureCategory: string
{
    case Connection = 'connection';
    case Authentication = 'authentication';
    case Constraint = 'constraint';
    case Timeout = 'timeout';
    case Deadlock = 'deadlock';
    case Serialization = 'serialization';
    case Transaction = 'transaction';
    case Statement = 'statement';
    case Driver = 'driver';
    case Unknown = 'unknown';
}
