# EvolvePHP Database Contracts

Portable SQL database statement and transaction contracts for EvolvePHP 2.

Package: `evolvephp/database-contracts`.

EvolvePHP 2 is pre-release. This package requires PHP `^8.4`, depends on `evolvephp/contracts`, and exposes public experimental API that may change before stable release.

The package defines the portable SQL database boundary: immutable SQL statements, execute/query operation names, a transaction callback contract, bounded operation classification and vendor-neutral failure categories. A transaction begins before the callback receives the active `DatabaseConnection`; normal callback return is committed and returned unchanged, while callback throwables require rollback and rethrow of the original throwable when rollback succeeds. Nested transactions and savepoints are outside this package's portable capability.

`query()` returns `iterable`, and that iterable may be lazy. Returning a query iterable from `transaction()` does not prolong or defer transaction completion: commit or rollback is controlled solely by the transaction callback returning or throwing, and a normal callback return is committed before `transaction()` itself returns. Portable callers that require transaction-scoped consistency, an active transactional cursor or transaction-owned query resources must iterate before the callback returns. Adapters must not keep a transaction silently open merely because a query iterable escaped the callback.

```php
$rows = $connection->transaction(
    static function (DatabaseConnection $connection): array {
        $rows = [];

        foreach ($connection->query($statement) as $row) {
            $rows[] = $row;
        }

        return $rows;
    },
);
```

The example materializes an array, but array materialization is not required by the contract. The portable rule is that transaction-sensitive iteration happens inside the transaction callback.

`DatabaseStatement` preserves accepted SQL and parameters unchanged. It validates nonblank SQL, positional or named parameter shape, scalar/null parameter values only, conservative named keys and bounded developer operation names. It does not parse, normalize, fingerprint, redact, log or derive operation names from SQL.

`DatabaseException` exposes safe diagnostic metadata: database operation, failure category, optional developer operation name, optional driver name, optional SQLSTATE and optional vendor code. Raw SQL and bound parameter values are not required or exposed by this contract.

This package is not a PDO implementation, not an ORM, not a query builder, not a migration runner, not a schema builder, not SQL logging and not telemetry. Concrete database adapters remain outward packages; PDO adapter work is separate.

Package publication has not started, and this package is not yet independently published. The canonical source remains the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

This package is provided under the BSD-3-Clause licence. See `LICENSE.md`.
