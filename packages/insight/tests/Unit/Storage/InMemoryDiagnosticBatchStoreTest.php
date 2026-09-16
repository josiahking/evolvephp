<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Storage;

use Evolve\Insight\Storage\DiagnosticBatchSnapshot;
use Evolve\Insight\Storage\InMemoryDiagnosticBatchStore;
use PHPUnit\Framework\TestCase;

final class InMemoryDiagnosticBatchStoreTest extends TestCase
{
    public function testSaveThenFindReturnsTheStoredSnapshot(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);
        $snapshot = $this->snapshot('execution-1');

        $store->save($snapshot);

        self::assertSame($snapshot, $store->find('execution-1'));
    }

    public function testZeroMaximumStoredBatchCountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stored diagnostic batch count must be positive.');

        new InMemoryDiagnosticBatchStore(0);
    }

    public function testNegativeMaximumStoredBatchCountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stored diagnostic batch count must be positive.');

        new InMemoryDiagnosticBatchStore(-1);
    }

    public function testUnknownFindReturnsNullAndExactIdentifierSemanticsAreUsed(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);
        $store->save($this->snapshot('execution-10'));

        self::assertNull($store->find('execution-1'));
        self::assertNull($store->find(''));
    }

    public function testMultipleSavesAndLatestAreDeterministicNewestFirst(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);
        $first = $this->snapshot('execution-1');
        $second = $this->snapshot('execution-2');
        $third = $this->snapshot('execution-3');

        $store->save($first);
        $store->save($second);
        $store->save($third);

        self::assertSame(array($third, $second, $first), $store->latest(10));
        self::assertSame(array($third, $second), $store->latest(2));
        self::assertSame(array($third, $second, $first), $store->latest(10));
    }

    public function testLatestLimitGreaterThanCountReturnsAllSnapshots(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);
        $first = $this->snapshot('execution-1');

        $store->save($first);

        self::assertSame(array($first), $store->latest(5));
    }

    public function testBelowCapacitySavesDoNotEvict(): void
    {
        $store = new InMemoryDiagnosticBatchStore(3);
        $first = $this->snapshot('execution-1');
        $second = $this->snapshot('execution-2');

        $store->save($first);
        $store->save($second);

        self::assertSame($first, $store->find('execution-1'));
        self::assertSame($second, $store->find('execution-2'));
        self::assertSame(array($second, $first), $store->latest(10));
    }

    public function testCapacityEvictsOldestSnapshotBeforeSavingUniqueSnapshot(): void
    {
        $store = new InMemoryDiagnosticBatchStore(2);
        $first = $this->snapshot('execution-1');
        $second = $this->snapshot('execution-2');
        $third = $this->snapshot('execution-3');

        $store->save($first);
        $store->save($second);
        $store->save($third);

        self::assertNull($store->find('execution-1'));
        self::assertSame($second, $store->find('execution-2'));
        self::assertSame($third, $store->find('execution-3'));
        self::assertSame(array($third, $second), $store->latest(10));
    }

    public function testMaximumOneRetainsOnlyNewestSuccessfullyStoredSnapshot(): void
    {
        $store = new InMemoryDiagnosticBatchStore(1);
        $first = $this->snapshot('execution-1');
        $second = $this->snapshot('execution-2');

        $store->save($first);
        $store->save($second);

        self::assertNull($store->find('execution-1'));
        self::assertSame($second, $store->find('execution-2'));
        self::assertSame(array($second), $store->latest(10));
    }

    public function testZeroLimitIsRejected(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Latest limit must be positive.');

        $store->latest(0);
    }

    public function testNegativeLimitIsRejected(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Latest limit must be positive.');

        $store->latest(-1);
    }

    public function testDuplicateIdentifierIsRejectedAndDoesNotReplaceOriginal(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);
        $original = $this->snapshot('execution-1', 'http-request');
        $duplicate = $this->snapshot('execution-1', 'cli-command');

        $store->save($original);

        try {
            $store->save($duplicate);
            self::fail('Expected duplicate execution identifier to be rejected.');
        } catch (\LogicException $exception) {
            self::assertSame('Diagnostic batch snapshot already exists for execution identifier.', $exception->getMessage());
        }

        self::assertSame($original, $store->find('execution-1'));
        self::assertSame(array($original), $store->latest(10));
    }

    public function testDuplicateAtCapacityDoesNotEvictOrMutateRetainedSnapshots(): void
    {
        $store = new InMemoryDiagnosticBatchStore(2);
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

        self::assertSame($first, $store->find('execution-1'));
        self::assertSame($second, $store->find('execution-2'));
        self::assertSame(array($second, $first), $store->latest(10));
    }

    private function snapshot(string $identifier, string $kind = 'http-request'): DiagnosticBatchSnapshot
    {
        return new DiagnosticBatchSnapshot($identifier, $kind, array(), 0);
    }
}
