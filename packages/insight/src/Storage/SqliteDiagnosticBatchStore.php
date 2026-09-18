<?php

declare(strict_types=1);

namespace Evolve\Insight\Storage;

use Evolve\Insight\Query\DiagnosticBatchPage;
use Evolve\Insight\Query\DiagnosticBatchQuery;
use Evolve\Insight\Query\DiagnosticBatchReader;
use Evolve\Insight\Query\DiagnosticBatchSummary;

final class SqliteDiagnosticBatchStore implements DiagnosticBatchStore, DiagnosticBatchReader
{
    private const string TABLE = 'insight_diagnostic_batches';

    private DiagnosticBatchSnapshotCodec $codec;

    public function __construct(
        private \PDO $pdo,
        private int $maximumStoredBatchCount,
        ?DiagnosticBatchSnapshotCodec $codec = null,
    ) {
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \InvalidArgumentException('Sqlite diagnostic batch store requires a SQLite PDO connection.');
        }

        if ($this->maximumStoredBatchCount <= 0) {
            throw new \InvalidArgumentException('Maximum stored diagnostic batch count must be positive.');
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

        $payload = $this->codec->encode($snapshot);

        $this->pruneOldestSnapshotsForIncomingSave();

        $statement = $this->prepare(
            'INSERT INTO ' . self::TABLE . ' (execution_identifier, snapshot_payload) VALUES (:execution_identifier, :snapshot_payload)'
        );
        $this->execute($statement, array(
            'execution_identifier' => $identifier,
            'snapshot_payload' => $payload,
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

    public function query(DiagnosticBatchQuery $query): DiagnosticBatchPage
    {
        $cursorSequence = null;

        if ($query->cursor() !== null) {
            $cursorSequence = $this->sequenceForCursor($query->cursor());
        }

        $sql = 'SELECT execution_identifier, snapshot_payload FROM ' . self::TABLE;

        if ($cursorSequence !== null) {
            $sql .= ' WHERE sequence < :cursor_sequence';
        }

        $sql .= ' ORDER BY sequence DESC';

        $statement = $this->prepare($sql);

        if ($cursorSequence !== null) {
            $statement->bindValue('cursor_sequence', $cursorSequence, \PDO::PARAM_INT);
        }

        if (!$statement->execute()) {
            throw new \RuntimeException('Failed to read diagnostic batch query results.');
        }

        $items = array();
        $hasOlderMatch = false;

        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $snapshot = $this->decodeRow($row);

            if (!$this->matchesQuery($snapshot, $query)) {
                continue;
            }

            if (count($items) < $query->pageSize()) {
                $items[] = DiagnosticBatchSummary::fromSnapshot($snapshot);

                continue;
            }

            $hasOlderMatch = true;
            break;
        }

        return new DiagnosticBatchPage(
            $items,
            $hasOlderMatch && $items !== array() ? $items[array_key_last($items)]->executionIdentifier() : null,
        );
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

    private function pruneOldestSnapshotsForIncomingSave(): void
    {
        $storedBatchCount = $this->storedBatchCount();

        if ($storedBatchCount < $this->maximumStoredBatchCount) {
            return;
        }

        $deleteCount = $storedBatchCount - $this->maximumStoredBatchCount + 1;
        $statement = $this->prepare(
            'DELETE FROM ' . self::TABLE . '
                WHERE sequence IN (
                    SELECT sequence FROM ' . self::TABLE . '
                    ORDER BY sequence ASC
                    LIMIT :delete_count
                )'
        );
        $statement->bindValue('delete_count', $deleteCount, \PDO::PARAM_INT);

        if (!$statement->execute()) {
            throw new \RuntimeException('Failed to execute SQLite diagnostic batch store statement.');
        }
    }

    private function storedBatchCount(): int
    {
        $statement = $this->prepare('SELECT COUNT(*) FROM ' . self::TABLE);
        $this->execute($statement, array());

        return (int) $statement->fetchColumn();
    }

    private function sequenceForCursor(string $cursor): int
    {
        $statement = $this->prepare(
            'SELECT sequence FROM ' . self::TABLE . ' WHERE execution_identifier = :execution_identifier'
        );
        $this->execute($statement, array('execution_identifier' => $cursor));

        $sequence = $statement->fetchColumn();

        if ($sequence === false) {
            throw new \InvalidArgumentException('Diagnostic query cursor does not reference a retained batch.');
        }

        return (int) $sequence;
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

    private function matchesQuery(DiagnosticBatchSnapshot $snapshot, DiagnosticBatchQuery $query): bool
    {
        if ($query->executionKind() !== null && $snapshot->executionKind() !== $query->executionKind()) {
            return false;
        }

        if ($query->diagnosticCategory() === null && $query->diagnosticName() === null) {
            return true;
        }

        foreach ($snapshot->diagnosticEntries() as $entry) {
            if ($query->diagnosticCategory() !== null && $entry->category() !== $query->diagnosticCategory()) {
                continue;
            }

            if ($query->diagnosticName() !== null && $entry->name() !== $query->diagnosticName()) {
                continue;
            }

            return true;
        }

        return false;
    }
}
