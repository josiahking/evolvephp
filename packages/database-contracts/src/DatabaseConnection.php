<?php

declare(strict_types=1);

namespace Evolve\Database\Contracts;

use Evolve\Database\Contracts\Exception\DatabaseException;
use Throwable;

/**
 * Portable SQL database connection contract.
 *
 * @experimental EvolvePHP 2 is pre-beta; this database contract may change before stable release.
 */
interface DatabaseConnection
{
    public function execute(DatabaseStatement $statement): int;

    /**
     * Execute a SQL query and return its result rows.
     *
     * The returned iterable may be lazy. When query() is called inside
     * transaction(), the iterable does not extend transaction lifetime by
     * escaping the callback. Transaction commit or rollback is controlled solely
     * by the transaction callback returning or throwing.
     *
     * Portable callers that require transaction-scoped consistency, an active
     * transactional cursor or transaction-owned query resources must consume the
     * rows before the callback returns. They must fully consume the iterable inside the transaction callback.
     * An adapter must not keep a transaction silently open merely because a query
     * iterable escaped the callback.
     *
     * @return iterable<array-key, mixed>
     */
    public function query(DatabaseStatement $statement): iterable;

    /**
     * Execute an operation inside one SQL transaction.
     *
     * The transaction begins before invoking the callback. The callback receives
     * the active connection whose operations participate in the transaction. A
     * normal callback return is followed by commit, and the callback result is
     * returned unchanged after a successful commit.
     *
     * If the callback throws, the adapter must roll back. When rollback succeeds,
     * the original throwable must be rethrown unchanged. Nested transaction()
     * calls are outside the portable Phase 10.1 capability; concrete adapters must
     * reject them deterministically through DatabaseException. Savepoint semantics
     * are not part of this contract.
     *
     * Returning a query iterable from the callback does not prolong or defer transaction completion. Once the callback returns normally, the adapter
     * commits before transaction() itself returns.
     *
     * @template TResult
     *
     * @param callable(DatabaseConnection): TResult $operation
     *
     * @return TResult
     *
     * @throws DatabaseException When transaction begin, commit, rollback or nested transaction handling fails.
     * @throws Throwable Re-throws the original callback throwable after a successful rollback.
     */
    public function transaction(callable $operation): mixed;
}
