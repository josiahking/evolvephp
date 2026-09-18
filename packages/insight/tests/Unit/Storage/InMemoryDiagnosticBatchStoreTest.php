<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Storage;

use Evolve\Insight\Storage\DiagnosticBatchSnapshot;
use Evolve\Insight\Storage\DiagnosticEntryAttributeSnapshot;
use Evolve\Insight\Storage\DiagnosticEntrySnapshot;
use Evolve\Insight\Storage\InMemoryDiagnosticBatchStore;
use Evolve\Insight\Query\DiagnosticBatchQuery;
use Evolve\Insight\Query\DiagnosticBatchSummary;
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

    public function testRichDiagnosticSnapshotUsesExistingFindLatestAndRetentionSemantics(): void
    {
        $store = new InMemoryDiagnosticBatchStore(1);
        $rich = new DiagnosticBatchSnapshot(
            'execution-1',
            'http-request',
            array(),
            0,
            array(new DiagnosticEntrySnapshot(
                'database',
                'query',
                array(new DiagnosticEntryAttributeSnapshot('statement', 'select-user')),
            )),
            1,
        );
        $newer = $this->snapshot('execution-2');

        $store->save($rich);
        self::assertSame($rich, $store->find('execution-1'));
        self::assertSame(array($rich), $store->latest(10));

        $store->save($newer);

        self::assertNull($store->find('execution-1'));
        self::assertSame(array($newer), $store->latest(10));
    }

    public function testQueryReturnsNewestFirstBoundedPagesWithoutDuplicatesOrMutation(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);
        $first = $this->snapshot('execution-1');
        $second = $this->snapshot('execution-2');
        $third = $this->snapshot('execution-3');
        $fourth = $this->snapshot('execution-4');

        foreach (array($first, $second, $third, $fourth) as $snapshot) {
            $store->save($snapshot);
        }

        $firstPage = $store->query(new DiagnosticBatchQuery(2));
        $secondPage = $store->query(new DiagnosticBatchQuery(2, $firstPage->nextCursor()));

        self::assertSame(array('execution-4', 'execution-3'), $this->summaryIdentifiers($firstPage->items()));
        self::assertSame('execution-3', $firstPage->nextCursor());
        self::assertSame(array('execution-2', 'execution-1'), $this->summaryIdentifiers($secondPage->items()));
        self::assertNull($secondPage->nextCursor());
        self::assertSame(array('execution-4', 'execution-3', 'execution-2', 'execution-1'), $this->summaryIdentifiers(array_merge($firstPage->items(), $secondPage->items())));
        self::assertSame(array($fourth, $third, $second, $first), $store->latest(10));
    }

    public function testQueryFiltersAreExactAndCombinedWithSameEntrySemantics(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);
        $first = $this->snapshot('execution-1', 'http-request', array(
            new DiagnosticEntrySnapshot('evolve.execution', 'handler-failed', array()),
            new DiagnosticEntrySnapshot('evolve.runtime', 'scope-close-failed', array()),
        ));
        $second = $this->snapshot('execution-2', 'queue-message', array(
            new DiagnosticEntrySnapshot('evolve.execution', 'scope-close-failed', array()),
        ));
        $third = $this->snapshot('execution-3', 'http-request', array(
            new DiagnosticEntrySnapshot('database', 'query', array()),
        ));

        foreach (array($first, $second, $third) as $snapshot) {
            $store->save($snapshot);
        }

        self::assertSame(array('execution-3', 'execution-1'), $this->summaryIdentifiers($store->query(new DiagnosticBatchQuery(10, executionKind: 'http-request'))->items()));
        self::assertSame(array('execution-2', 'execution-1'), $this->summaryIdentifiers($store->query(new DiagnosticBatchQuery(10, diagnosticCategory: 'evolve.execution'))->items()));
        self::assertSame(array('execution-2', 'execution-1'), $this->summaryIdentifiers($store->query(new DiagnosticBatchQuery(10, diagnosticName: 'scope-close-failed'))->items()));
        self::assertSame(array('execution-2'), $this->summaryIdentifiers($store->query(new DiagnosticBatchQuery(10, diagnosticCategory: 'evolve.execution', diagnosticName: 'scope-close-failed'))->items()));
        self::assertSame(array(), $this->summaryIdentifiers($store->query(new DiagnosticBatchQuery(10, executionKind: 'http-request', diagnosticCategory: 'evolve.execution', diagnosticName: 'scope-close-failed'))->items()));
    }

    public function testFilteredQueryPaginationSkipsInterleavedNonMatchesWithoutSkippingMatches(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);

        foreach (array(
            $this->nonMatchingSnapshot('execution-0', 'queue-message'),
            $this->matchingSnapshot('execution-1'),
            $this->nonMatchingSnapshot('execution-2', 'http-request'),
            $this->matchingSnapshot('execution-3'),
            $this->sameEntryNonMatchingSnapshot('execution-4'),
            $this->matchingSnapshot('execution-5'),
        ) as $snapshot) {
            $store->save($snapshot);
        }

        $query = new DiagnosticBatchQuery(
            2,
            executionKind: 'http-request',
            diagnosticCategory: 'evolve.execution',
            diagnosticName: 'handler-failed',
        );
        $firstPage = $store->query($query);
        $secondPage = $store->query(new DiagnosticBatchQuery(
            2,
            $firstPage->nextCursor(),
            executionKind: 'http-request',
            diagnosticCategory: 'evolve.execution',
            diagnosticName: 'handler-failed',
        ));

        self::assertSame(array('execution-5', 'execution-3'), $this->summaryIdentifiers($firstPage->items()));
        self::assertSame('execution-3', $firstPage->nextCursor());
        self::assertSame(array('execution-1'), $this->summaryIdentifiers($secondPage->items()));
        self::assertNull($secondPage->nextCursor());
        self::assertSame(array('execution-5', 'execution-3', 'execution-1'), $this->summaryIdentifiers(array_merge($firstPage->items(), $secondPage->items())));
    }

    public function testQueryRejectsUnknownAndPrunedCursorsButRetainedCursorContinues(): void
    {
        $store = new InMemoryDiagnosticBatchStore(2);
        $store->save($this->snapshot('execution-1'));
        $store->save($this->snapshot('execution-2'));
        $store->save($this->snapshot('execution-3'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic query cursor does not reference a retained batch.');

        $store->query(new DiagnosticBatchQuery(1, 'execution-1'));
    }

    public function testQueryRejectsUnknownCursorAndValidRetainedCursorContinues(): void
    {
        $store = new InMemoryDiagnosticBatchStore(3);
        $store->save($this->snapshot('execution-1'));
        $store->save($this->snapshot('execution-2'));
        $store->save($this->snapshot('execution-3'));

        self::assertSame(array('execution-1'), $this->summaryIdentifiers($store->query(new DiagnosticBatchQuery(10, 'execution-2'))->items()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic query cursor does not reference a retained batch.');

        $store->query(new DiagnosticBatchQuery(1, 'missing-execution'));
    }

    /**
     * @param list<DiagnosticEntrySnapshot> $entries
     */
    private function snapshot(string $identifier, string $kind = 'http-request', array $entries = array()): DiagnosticBatchSnapshot
    {
        return new DiagnosticBatchSnapshot($identifier, $kind, array(), 0, $entries);
    }

    private function matchingSnapshot(string $identifier): DiagnosticBatchSnapshot
    {
        return $this->snapshot($identifier, 'http-request', array(
            new DiagnosticEntrySnapshot('evolve.execution', 'handler-failed', array()),
        ));
    }

    private function nonMatchingSnapshot(string $identifier, string $kind): DiagnosticBatchSnapshot
    {
        return $this->snapshot($identifier, $kind, array(
            new DiagnosticEntrySnapshot('evolve.runtime', 'scope-close-failed', array()),
        ));
    }

    private function sameEntryNonMatchingSnapshot(string $identifier): DiagnosticBatchSnapshot
    {
        return $this->snapshot($identifier, 'http-request', array(
            new DiagnosticEntrySnapshot('evolve.execution', 'scope-close-failed', array()),
            new DiagnosticEntrySnapshot('evolve.runtime', 'handler-failed', array()),
        ));
    }

    /**
     * @param list<DiagnosticBatchSummary> $summaries
     *
     * @return list<string>
     */
    private function summaryIdentifiers(array $summaries): array
    {
        return array_map(static fn ($summary): string => $summary->executionIdentifier(), $summaries);
    }
}
