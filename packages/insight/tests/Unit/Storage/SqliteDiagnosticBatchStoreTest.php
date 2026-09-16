<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Storage;

use Evolve\Insight\Storage\DiagnosticBatchSnapshot;
use Evolve\Insight\Storage\DiagnosticObservationSnapshot;
use Evolve\Insight\Storage\SqliteDiagnosticBatchStore;
use PHPUnit\Framework\TestCase;

final class SqliteDiagnosticBatchStoreTest extends TestCase
{
    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
    }

    public function testSuccessfulExplicitSqliteConstruction(): void
    {
        self::assertSame(array(), (new SqliteDiagnosticBatchStore($this->pdo(), 10))->latest(1));
    }

    public function testZeroMaximumStoredBatchCountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stored diagnostic batch count must be positive.');

        new SqliteDiagnosticBatchStore($this->pdo(), 0);
    }

    public function testNegativeMaximumStoredBatchCountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stored diagnostic batch count must be positive.');

        new SqliteDiagnosticBatchStore($this->pdo(), -1);
    }

    public function testNonSqlitePdoConnectionIsRejected(): void
    {
        $pdo = new class extends \PDO {
            public function __construct() {}

            public function getAttribute(int $attribute): mixed
            {
                if ($attribute === \PDO::ATTR_DRIVER_NAME) {
                    return 'mysql';
                }

                return null;
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sqlite diagnostic batch store requires a SQLite PDO connection.');

        new SqliteDiagnosticBatchStore($pdo, 10);
    }

    public function testConstructionCreatesSchema(): void
    {
        $pdo = $this->pdo();

        new SqliteDiagnosticBatchStore($pdo, 10);

        self::assertSame(
            'insight_diagnostic_batches',
            $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'insight_diagnostic_batches'")->fetchColumn(),
        );
    }

    public function testSaveThenExactFind(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo(), 10);
        $snapshot = $this->snapshot('execution-1');

        $store->save($snapshot);

        self::assertEquals($snapshot, $store->find('execution-1'));
        self::assertNull($store->find('execution'));
        self::assertNull($store->find(''));
    }

    public function testMultipleSavesAndLatestAreDeterministicNewestFirst(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo(), 10);
        $first = $this->snapshot('execution-1');
        $second = $this->snapshot('execution-2');
        $third = $this->snapshot('execution-3');

        $store->save($first);
        $store->save($second);
        $store->save($third);

        self::assertEquals(array($third, $second, $first), $store->latest(10));
        self::assertEquals(array($third, $second), $store->latest(2));
        self::assertEquals(array($third, $second, $first), $store->latest(10));
    }

    public function testLatestLimitGreaterThanCountReturnsAllSnapshots(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo(), 3);
        $snapshot = $this->snapshot('execution-1');

        $store->save($snapshot);

        self::assertEquals(array($snapshot), $store->latest(5));
    }

    public function testBelowCapacitySavesDoNotEvict(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo(), 3);
        $first = $this->snapshot('execution-1');
        $second = $this->snapshot('execution-2');

        $store->save($first);
        $store->save($second);

        self::assertEquals($first, $store->find('execution-1'));
        self::assertEquals($second, $store->find('execution-2'));
        self::assertEquals(array($second, $first), $store->latest(10));
    }

    public function testCapacityEvictsOldestSnapshotBeforeSavingUniqueSnapshot(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo(), 2);
        $first = $this->snapshot('execution-1');
        $second = $this->snapshot('execution-2');
        $third = $this->snapshot('execution-3');

        $store->save($first);
        $store->save($second);
        $store->save($third);

        self::assertNull($store->find('execution-1'));
        self::assertEquals($second, $store->find('execution-2'));
        self::assertEquals($third, $store->find('execution-3'));
        self::assertEquals(array($third, $second), $store->latest(10));
    }

    public function testMaximumOneRetainsOnlyNewestSuccessfullyStoredSnapshot(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo(), 1);
        $first = $this->snapshot('execution-1');
        $second = $this->snapshot('execution-2');

        $store->save($first);
        $store->save($second);

        self::assertNull($store->find('execution-1'));
        self::assertEquals($second, $store->find('execution-2'));
        self::assertEquals(array($second), $store->latest(10));
    }

    public function testRetentionSurvivesReopeningSameDatabase(): void
    {
        $path = $this->temporaryDatabasePath();
        $first = $this->snapshot('execution-1');
        $second = $this->snapshot('execution-2');
        $third = $this->snapshot('execution-3');

        (new SqliteDiagnosticBatchStore($this->pdo($path), 2))->save($first);
        (new SqliteDiagnosticBatchStore($this->pdo($path), 2))->save($second);
        $reopened = new SqliteDiagnosticBatchStore($this->pdo($path), 2);
        $reopened->save($third);

        self::assertNull($reopened->find('execution-1'));
        self::assertEquals(array($third, $second), $reopened->latest(10));
    }

    public function testNonPositiveLatestLimitIsRejected(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo(), 10);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Latest limit must be positive.');

        $store->latest(0);
    }

    public function testDuplicateIdentifierIsRejectedAndDoesNotReplaceOriginal(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo(), 10);
        $original = $this->snapshot('execution-1', 'http-request');
        $duplicate = $this->snapshot('execution-1', 'cli-command');

        $store->save($original);

        try {
            $store->save($duplicate);
            self::fail('Expected duplicate execution identifier to be rejected.');
        } catch (\LogicException $exception) {
            self::assertSame('Diagnostic batch snapshot already exists for execution identifier.', $exception->getMessage());
        }

        self::assertEquals($original, $store->find('execution-1'));
        self::assertEquals(array($original), $store->latest(10));
    }

    public function testDuplicateAtCapacityDoesNotEvictOrMutateRetainedSnapshots(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo(), 2);
        $first = $this->snapshot('execution-1', 'http-request');
        $second = $this->snapshot('execution-2', 'queue-message');
        $duplicate = $this->snapshot('execution-1', 'cli-command');

        $store->save($first);
        $store->save($second);

        try {
            $store->save($duplicate);
            self::fail('Expected duplicate execution identifier to be rejected.');
        } catch (\LogicException $exception) {
            self::assertSame('Diagnostic batch snapshot already exists for execution identifier.', $exception->getMessage());
        }

        self::assertEquals($first, $store->find('execution-1'));
        self::assertEquals($second, $store->find('execution-2'));
        self::assertEquals(array($second, $first), $store->latest(10));
    }

    public function testExistingOverCapacityRowsAreNotPrunedByConstructionButAreReducedOnNextUniqueSave(): void
    {
        $pdo = $this->pdo();
        new SqliteDiagnosticBatchStore($pdo, 10);
        $this->insertRaw($pdo, 'execution-1', $this->payload('execution-1'));
        $this->insertRaw($pdo, 'execution-2', $this->payload('execution-2'));
        $this->insertRaw($pdo, 'execution-3', $this->payload('execution-3'));

        $store = new SqliteDiagnosticBatchStore($pdo, 2);

        self::assertSame(3, $this->countRows($pdo));

        $fourth = $this->snapshot('execution-4');
        $store->save($fourth);

        self::assertSame(2, $this->countRows($pdo));
        self::assertNull($store->find('execution-1'));
        self::assertNull($store->find('execution-2'));
        self::assertEquals(array($fourth, $this->snapshot('execution-3')), $store->latest(10));
    }

    public function testUnsupportedCandidateSnapshotFailsEncodingBeforePruning(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo(), 1);
        $original = $this->snapshot('execution-1');
        $unsupported = $this->snapshot('execution-2', 'unsupported-kind');

        $store->save($original);

        try {
            $store->save($unsupported);
            self::fail('Expected unsupported candidate snapshot to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Execution kind is not supported by this diagnostic snapshot format.', $exception->getMessage());
        }

        self::assertEquals($original, $store->find('execution-1'));
        self::assertNull($store->find('execution-2'));
        self::assertEquals(array($original), $store->latest(10));
    }

    public function testPruneFailurePreventsIncomingInsert(): void
    {
        $pdo = $this->pdo();
        $store = new SqliteDiagnosticBatchStore($pdo, 1);
        $original = $this->snapshot('execution-1');
        $candidate = $this->snapshot('execution-2');

        $store->save($original);
        $pdo->exec(
            "CREATE TRIGGER block_diagnostic_batch_delete
                BEFORE DELETE ON insight_diagnostic_batches
                BEGIN
                    SELECT RAISE(ABORT, 'delete blocked');
                END"
        );

        $this->expectException(\RuntimeException::class);

        try {
            $store->save($candidate);
        } finally {
            self::assertEquals($original, $store->find('execution-1'));
            self::assertNull($store->find('execution-2'));
        }
    }

    public function testPayloadIndexIdentifierMismatchIsRejectedAsCorruption(): void
    {
        $pdo = $this->pdo();
        new SqliteDiagnosticBatchStore($pdo, 10);
        $this->insertRaw($pdo, 'indexed-id', '{"version":1,"execution_identifier":"payload-id","execution_kind":"http-request","observations":[],"dropped_observation_count":0}');
        $store = new SqliteDiagnosticBatchStore($pdo, 10);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Persisted diagnostic batch identifier does not match its index.');

        $store->find('indexed-id');
    }

    public function testCorruptPayloadReadFailsExplicitly(): void
    {
        $pdo = $this->pdo();
        new SqliteDiagnosticBatchStore($pdo, 10);
        $this->insertRaw($pdo, 'execution-1', '{');
        $store = new SqliteDiagnosticBatchStore($pdo, 10);

        $this->expectException(\InvalidArgumentException::class);

        $store->find('execution-1');
    }

    public function testConstructorDoesNotEagerlyDecodeCorruptExistingRows(): void
    {
        $pdo = $this->pdo();
        new SqliteDiagnosticBatchStore($pdo, 10);
        $this->insertRaw($pdo, 'execution-1', '{');

        self::assertNull((new SqliteDiagnosticBatchStore($pdo, 10))->find('missing-execution'));
    }

    public function testObservationOrderAndNullableFieldsSurvivePersistence(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo(), 10);
        $snapshot = new DiagnosticBatchSnapshot(
            'execution-1',
            'http-request',
            array(
                new DiagnosticObservationSnapshot('execution-started', null, null, null),
                new DiagnosticObservationSnapshot('handler-completed', 'failed', 'RuntimeException', null),
                new DiagnosticObservationSnapshot('execution-completed', 'succeeded', null, 'reusable'),
            ),
            2,
        );

        $store->save($snapshot);
        $found = $store->find('execution-1');

        self::assertEquals($snapshot, $found);
        self::assertSame(
            array('execution-started', 'handler-completed', 'execution-completed'),
            array_map(
                static fn (DiagnosticObservationSnapshot $observation): string => $observation->type(),
                $found?->observations() ?? array(),
            ),
        );
        self::assertNull($found?->observations()[0]->outcome());
        self::assertNull($found?->observations()[1]->reuseDecision());
    }

    private function pdo(?string $path = null): \PDO
    {
        return new \PDO($path === null ? 'sqlite::memory:' : 'sqlite:' . $path);
    }

    private function snapshot(string $identifier, string $kind = 'http-request'): DiagnosticBatchSnapshot
    {
        return new DiagnosticBatchSnapshot($identifier, $kind, array(), 0);
    }

    private function insertRaw(\PDO $pdo, string $identifier, string $payload): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO insight_diagnostic_batches (execution_identifier, snapshot_payload) VALUES (:execution_identifier, :snapshot_payload)'
        );
        $statement->execute(array(
            'execution_identifier' => $identifier,
            'snapshot_payload' => $payload,
        ));
    }

    private function payload(string $identifier): string
    {
        return '{"version":1,"execution_identifier":"' . $identifier . '","execution_kind":"http-request","observations":[],"dropped_observation_count":0}';
    }

    private function countRows(\PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM insight_diagnostic_batches')->fetchColumn();
    }

    private function temporaryDatabasePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'evolve-insight-');

        if ($path === false) {
            throw new \RuntimeException('Failed to create temporary SQLite database path.');
        }

        return $path;
    }
}
