<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Query;

use Evolve\Insight\Access\DiagnosticAccessDenied;
use Evolve\Insight\Access\DiagnosticAccessOperation;
use Evolve\Insight\Access\DiagnosticAccessPolicy;
use Evolve\Insight\Query\DiagnosticBatchPage;
use Evolve\Insight\Query\DiagnosticBatchQuery;
use Evolve\Insight\Query\DiagnosticBatchReader;
use Evolve\Insight\Query\DiagnosticBatchSummary;
use Evolve\Insight\Query\DiagnosticQueryService;
use Evolve\Insight\Storage\DiagnosticBatchSnapshot;
use Evolve\Insight\Storage\DiagnosticEntrySnapshot;
use PHPUnit\Framework\TestCase;

final class DiagnosticQueryServiceTest extends TestCase
{
    public function testQueryRejectsInvalidPageSizesAndFilters(): void
    {
        foreach (array(0, -1, 101) as $pageSize) {
            try {
                new DiagnosticBatchQuery($pageSize);
                self::fail('Expected invalid page size to be rejected.');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame(1, (new DiagnosticBatchQuery(1))->pageSize());
        self::assertSame(100, (new DiagnosticBatchQuery(100))->pageSize());

        foreach (array(
            static fn (): DiagnosticBatchQuery => new DiagnosticBatchQuery(10, executionKind: ''),
            static fn (): DiagnosticBatchQuery => new DiagnosticBatchQuery(10, diagnosticCategory: ''),
            static fn (): DiagnosticBatchQuery => new DiagnosticBatchQuery(10, diagnosticName: ''),
            static fn (): DiagnosticBatchQuery => new DiagnosticBatchQuery(10, executionKind: 'web-request'),
        ) as $factory) {
            try {
                $factory();
                self::fail('Expected invalid query input to be rejected.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testSummaryAndPageExposeOnlyDetachedPrimitiveListViewData(): void
    {
        $snapshot = new DiagnosticBatchSnapshot(
            'execution-1',
            'http-request',
            array(),
            2,
            array(
                new DiagnosticEntrySnapshot('database', 'query', array()),
                new DiagnosticEntrySnapshot('runtime', 'handler-failed', array()),
            ),
            3,
        );
        $summary = DiagnosticBatchSummary::fromSnapshot($snapshot);
        $page = new DiagnosticBatchPage(array($summary), 'execution-1');

        self::assertSame('execution-1', $summary->executionIdentifier());
        self::assertSame('http-request', $summary->executionKind());
        self::assertSame(0, $summary->observationCount());
        self::assertSame(2, $summary->diagnosticEntryCount());
        self::assertSame(2, $summary->droppedObservationCount());
        self::assertSame(3, $summary->droppedDiagnosticEntryCount());
        self::assertSame(array($summary), $page->items());
        self::assertSame('execution-1', $page->nextCursor());
    }

    public function testPageRejectsNonSummaryItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic batch page items must be diagnostic batch summaries.');

        new DiagnosticBatchPage(array(new \stdClass()), null);
    }

    public function testPageAcceptsAtMostOneHundredSummaries(): void
    {
        $summary = DiagnosticBatchSummary::fromSnapshot(new DiagnosticBatchSnapshot(
            'execution-1',
            'http-request',
            array(),
            0,
        ));
        $acceptedItems = array_fill(0, 100, $summary);
        $acceptedPage = new DiagnosticBatchPage($acceptedItems, 'execution-1');

        self::assertSame($acceptedItems, $acceptedPage->items());
        self::assertSame('execution-1', $acceptedPage->nextCursor());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic batch page must not contain more than 100 items.');

        new DiagnosticBatchPage(array_fill(0, 101, $summary), null);
    }

    public function testDeniedListThrowsBeforeReaderQueryCall(): void
    {
        $reader = new RecordingDiagnosticBatchReader();
        $policy = new RecordingDiagnosticAccessPolicy(false);
        $service = new DiagnosticQueryService($reader, $policy);

        $this->expectException(DiagnosticAccessDenied::class);
        $this->expectExceptionMessage('Insight diagnostic list access denied.');

        try {
            $service->query(new DiagnosticBatchQuery(10));
        } finally {
            self::assertSame(0, $reader->queryCalls);
            self::assertSame(array(array(DiagnosticAccessOperation::List, null)), $policy->calls);
        }
    }

    public function testDeniedDetailThrowsBeforeReaderFindCall(): void
    {
        $reader = new RecordingDiagnosticBatchReader();
        $policy = new RecordingDiagnosticAccessPolicy(false);
        $service = new DiagnosticQueryService($reader, $policy);

        $this->expectException(DiagnosticAccessDenied::class);
        $this->expectExceptionMessage('Insight diagnostic detail access denied.');

        try {
            $service->find('execution-1');
        } finally {
            self::assertSame(0, $reader->findCalls);
            self::assertSame(array(array(DiagnosticAccessOperation::Detail, 'execution-1')), $policy->calls);
        }
    }

    public function testAllowedListAndDetailDelegateExactlyOnceAndReturnReaderResults(): void
    {
        $page = new DiagnosticBatchPage(array(), null);
        $snapshot = new DiagnosticBatchSnapshot('execution-1', 'http-request', array(), 0);
        $reader = new RecordingDiagnosticBatchReader($page, $snapshot);
        $policy = new RecordingDiagnosticAccessPolicy(true);
        $service = new DiagnosticQueryService($reader, $policy);
        $query = new DiagnosticBatchQuery(10);

        self::assertSame($page, $service->query($query));
        self::assertSame($snapshot, $service->find('execution-1'));

        $reader->snapshot = null;
        self::assertNull($service->find('execution-missing'));

        self::assertSame(1, $reader->queryCalls);
        self::assertSame(2, $reader->findCalls);
        self::assertSame($query, $reader->lastQuery);
        self::assertSame('execution-missing', $reader->lastFindIdentifier);
        self::assertSame(array(
            array(DiagnosticAccessOperation::List, null),
            array(DiagnosticAccessOperation::Detail, 'execution-1'),
            array(DiagnosticAccessOperation::Detail, 'execution-missing'),
        ), $policy->calls);
    }
}

final class RecordingDiagnosticBatchReader implements DiagnosticBatchReader
{
    public int $queryCalls = 0;

    public int $findCalls = 0;

    public ?DiagnosticBatchQuery $lastQuery = null;

    public ?string $lastFindIdentifier = null;

    public function __construct(
        private ?DiagnosticBatchPage $page = null,
        public ?DiagnosticBatchSnapshot $snapshot = null,
    ) {}

    public function find(string $executionIdentifier): ?DiagnosticBatchSnapshot
    {
        $this->findCalls++;
        $this->lastFindIdentifier = $executionIdentifier;

        return $this->snapshot;
    }

    public function query(DiagnosticBatchQuery $query): DiagnosticBatchPage
    {
        $this->queryCalls++;
        $this->lastQuery = $query;

        return $this->page ?? new DiagnosticBatchPage(array(), null);
    }
}

final class RecordingDiagnosticAccessPolicy implements DiagnosticAccessPolicy
{
    /**
     * @var list<array{DiagnosticAccessOperation, ?string}>
     */
    public array $calls = array();

    public function __construct(private bool $allowed) {}

    public function allows(DiagnosticAccessOperation $operation, ?string $executionIdentifier = null): bool
    {
        $this->calls[] = array($operation, $executionIdentifier);

        return $this->allowed;
    }
}
