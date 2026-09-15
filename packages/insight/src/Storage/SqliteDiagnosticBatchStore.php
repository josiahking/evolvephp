<?php

declare(strict_types=1);

namespace Evolve\Insight\Storage;

final class SqliteDiagnosticBatchStore implements DiagnosticBatchStore
{
    private const string TABLE = 'insight_diagnostic_batches';

    private DiagnosticBatchSnapshotCodec $codec;

    public function __construct(private \PDO $pdo, ?DiagnosticBatchSnapshotCodec $codec = null)
    {
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \InvalidArgumentException('Sqlite diagnostic batch store requires a SQLite PDO connection.');
        }

        $this->codec = $codec ?? new DiagnosticBatchSnapshotCodec();
        $this->createSchema();
    }

    public function save(DiagnosticBatchSnapshot $snapshot): void
    {
        $identifier = $snapshot->executionIdentifier();

        if ($this->identifierExists($identifier)) {
            throw new \LogicException('Diagnostic batch snapshot already exists for execution identifier.');
        }

        $statement = $this->prepare(
            'INSERT INTO ' . self::TABLE . ' (execution_identifier, snapshot_payload) VALUES (:execution_identifier, :snapshot_payload)'
        );
        $this->execute($statement, array(
            'execution_identifier' => $identifier,
            'snapshot_payload' => $this->codec->encode($snapshot),
        ));
    }

    public function find(string $executionIdentifier): ?DiagnosticBatchSnapshot
    {
        $statement = $this->prepare(
            'SELECT execution_identifier, snapshot_payload FROM ' . self::TABLE . ' WHERE execution_identifier = :execution_identifier'
        );
        $this->execute($statement, array('execution_identifier' => $executionIdentifier));

        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->decodeRow($row);
    }

    public function latest(int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Latest limit must be positive.');
        }

        $statement = $this->prepare(
            'SELECT execution_identifier, snapshot_payload FROM ' . self::TABLE . ' ORDER BY sequence DESC LIMIT :limit'
        );
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);

        if (!$statement->execute()) {
            throw new \RuntimeException('Failed to read latest diagnostic batch snapshots.');
        }

        $snapshots = array();

        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $snapshots[] = $this->decodeRow($row);
        }

        return $snapshots;
    }

    private function createSchema(): void
    {
        $this->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                sequence INTEGER PRIMARY KEY AUTOINCREMENT,
                execution_identifier TEXT NOT NULL UNIQUE,
                snapshot_payload TEXT NOT NULL
            )'
        );
        $this->exec(
            'CREATE INDEX IF NOT EXISTS insight_diagnostic_batches_execution_identifier_idx
                ON ' . self::TABLE . ' (execution_identifier)'
        );
    }

    private function identifierExists(string $identifier): bool
    {
        $statement = $this->prepare(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE execution_identifier = :execution_identifier'
        );
        $this->execute($statement, array('execution_identifier' => $identifier));

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function decodeRow(array $row): DiagnosticBatchSnapshot
    {
        if (!is_string($row['execution_identifier'] ?? null) || !is_string($row['snapshot_payload'] ?? null)) {
            throw new \UnexpectedValueException('Persisted diagnostic batch row has an invalid shape.');
        }

        $snapshot = $this->codec->decode($row['snapshot_payload']);

        if ($snapshot->executionIdentifier() !== $row['execution_identifier']) {
            throw new \UnexpectedValueException('Persisted diagnostic batch identifier does not match its index.');
        }

        return $snapshot;
    }

    private function exec(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            throw new \RuntimeException('Failed to initialize SQLite diagnostic batch store schema.');
        }
    }

    private function prepare(string $sql): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Failed to prepare SQLite diagnostic batch store statement.');
        }

        return $statement;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function execute(\PDOStatement $statement, array $parameters): void
    {
        if (!$statement->execute($parameters)) {
            throw new \RuntimeException('Failed to execute SQLite diagnostic batch store statement.');
        }
    }
}
