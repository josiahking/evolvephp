<?php

declare(strict_types=1);

namespace Evolve\Database\Contracts;

/**
 * Bounded portable database operation vocabulary.
 *
 * @experimental EvolvePHP 2 is pre-beta; this database contract may change before stable release.
 */
enum DatabaseOperation: string
{
    case Execute = 'execute';
    case Query = 'query';
    case TransactionBegin = 'transaction.begin';
    case TransactionCommit = 'transaction.commit';
    case TransactionRollback = 'transaction.rollback';
}
