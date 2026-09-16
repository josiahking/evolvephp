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
        self::assertSame(array(), (new SqliteDiagnosticBatchStore($this->pdo()))->latest(1));
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

        new SqliteDiagnosticBatchStore($pdo);
    }

    public function testConstructionCreatesSchema(): void
    {
        $pdo = $this->pdo();

        new SqliteDiagnosticBatchStore($pdo);

        self::assertSame(
            'insight_diagnostic_batches',
            $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'insight_diagnostic_batches'")->fetchColumn(),
        );
    }

    public function testSaveThenExactFind(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo());
        $snapshot = $this->snapshot('execution-1');

        $store->save($snapshot);

        self::assertEquals($snapshot, $store->find('execution-1'));
        self::assertNull($store->find('execution'));
        self::assertNull($store->find(''));
    }

    public function testMultipleSavesAndLatestAreDeterministicNewestFirst(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo());
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
        $store = new SqliteDiagnosticBatchStore($this->pdo());
        $snapshot = $this->snapshot('execution-1');

        $store->save($snapshot);

        self::assertEquals(array($snapshot), $store->latest(5));
    }

    public function testNonPositiveLatestLimitIsRejected(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Latest limit must be positive.');

        $store->latest(0);
    }

    public function testDuplicateIdentifierIsRejectedAndDoesNotReplaceOriginal(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo());
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

    public function testPayloadIndexIdentifierMismatchIsRejectedAsCorruption(): void
    {
        $pdo = $this->pdo();
        new SqliteDiagnosticBatchStore($pdo);
        $this->insertRaw($pdo, 'indexed-id', '{"version":1,"execution_identifier":"payload-id","execution_kind":"http-request","observations":[],"dropped_observation_count":0}');
        $store = new SqliteDiagnosticBatchStore($pdo);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Persisted diagnostic batch identifier does not match its index.');

        $store->find('indexed-id');
    }

    public function testCorruptPayloadReadFailsExplicitly(): void
    {
        $pdo = $this->pdo();
        new SqliteDiagnosticBatchStore($pdo);
        $this->insertRaw($pdo, 'execution-1', '{');
        $store = new SqliteDiagnosticBatchStore($pdo);

        $this->expectException(\InvalidArgumentException::class);

        $store->find('execution-1');
    }

    public function testConstructorDoesNotEagerlyDecodeCorruptExistingRows(): void
    {
        $pdo = $this->pdo();
        new SqliteDiagnosticBatchStore($pdo);
        $this->insertRaw($pdo, 'execution-1', '{');

        self::assertNull((new SqliteDiagnosticBatchStore($pdo))->find('missing-execution'));
    }

    public function testObservationOrderAndNullableFieldsSurvivePersistence(): void
    {
        $store = new SqliteDiagnosticBatchStore($this->pdo());
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

    private function pdo(): \PDO
    {
        return new \PDO('sqlite::memory:');
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
}
