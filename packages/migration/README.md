# EvolvePHP Migration

`evolvephp/migration`: Explicit one-shot migration runtime for EvolvePHP 2. EvolvePHP 2 is pre-release, and this experimental public API may change before Stable.

The package requires PHP `^8.4`, `evolvephp/contracts`, `evolvephp/core`, `evolvephp/database-contracts` and `evolvephp/lock-contracts`. It is not yet independently published. The canonical source is the [EvolvePHP monorepo](https://github.com/josiahking/evolvephp). It uses the BSD-3-Clause licence in `LICENSE.md`.

Applications supply `MigrationContributor` objects explicitly. Each definition carries an application or module owner, a local identifier, a non-negative order, an explicit lowercase SHA-256 checksum, an action, and a transaction mode. The registry sorts by order, owner and identifier. The same local identifier may belong to different owners. There is no filesystem or component discovery.

`MigrationPlanner` compares definitions with a durable `MigrationHistoryStore` supplied by the application. Pending definitions have no history; applied definitions match both order and checksum; drifted definitions differ; orphaned applied records have no current definition. Drift blocks execution. Orphaned records remain visible without blocking unrelated forward work. Planning is read-only.

`MigrationRunner::run()` is one-shot. It makes one non-blocking lock attempt when pending work exists, refreshes the plan under the acquired lease, and runs pending actions sequentially. A successful action is recorded only after it returns. Contention returns a structured non-executed report. Action failure stops later migrations and preserves the original throwable. A history-write failure after an action is uncertain and makes that runner terminal. Lease release is attempted once on every acquired path; cleanup failure is reported separately and makes the runner terminal. There is no exactly-once guarantee or atomicity across arbitrary action effects and history recording.

Transaction mode `None` invokes the action directly with an injected `DatabaseConnection` or null. Mode `Database` requires a connection and uses its `transaction()` callback. Providers choose this mode only when their migration and backend are safe for that boundary. EvolvePHP makes no claim that all schema DDL is transactional. This package supplies neither a PDO adapter nor a framework-owned history table or schema builder.

`MigrateCommand` is explicitly added to an application `CommandRegistry`. `migrate` applies pending work; `migrate --dry-run` and `migrate --status` inspect the plan without locking, running actions or writing history. Unsupported input is rejected. CLI output uses bounded status counts and does not print SQL, action payloads, secrets or stack traces.

There is no automatic migration on application boot, no down migration, no rollback, no uninstall schema removal, and no process loop. Runtime and deployment composition remain application responsibilities.
