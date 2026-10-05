<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Storage;

use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\Storage\DiagnosticBatchSnapshot;
use Evolve\Insight\Storage\DiagnosticEntryAttributeSnapshot;
use Evolve\Insight\Storage\DiagnosticEntrySnapshot;
use Evolve\Insight\Storage\DiagnosticObservationSnapshot;
use PHPUnit\Framework\TestCase;

final class DiagnosticBatchSnapshotTest extends TestCase
{
    public function testIdentifierKindDroppedCountAndObservationOrderArePreserved(): void
    {
        $first = new DiagnosticObservationSnapshot('execution-started', null, null, null);
        $second = new DiagnosticObservationSnapshot('execution-completed', 'succeeded', null, 'reusable');

        $snapshot = new DiagnosticBatchSnapshot(
            'execution-123',
            'http-request',
            [$first, $second],
            2,
        );

        self::assertSame('execution-123', $snapshot->executionIdentifier());
        self::assertSame('http-request', $snapshot->executionKind());
        self::assertSame([$first, $second], $snapshot->observations());
        self::assertSame(2, $snapshot->droppedObservationCount());

        self::assertContainsOnlyInstancesOf(DiagnosticObservationSnapshot::class, $snapshot->observations());
    }

    public function testObservationCollectionsDoNotExposeMutableInternalState(): void
    {
        $first = new DiagnosticObservationSnapshot('execution-started', null, null, null);
        $snapshot = new DiagnosticBatchSnapshot(
            'execution-123',
            'http-request',
            [$first],
            0,
        );

        $observations = $snapshot->observations();
        $observations[] = new DiagnosticObservationSnapshot('execution-completed', null, null, null);

        self::assertSame([$first], $snapshot->observations());
    }

    public function testInvalidObservationObjectIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic observations must be diagnostic observation snapshots.');

        new DiagnosticBatchSnapshot('execution-123', 'http-request', [new \stdClass()], 0);
    }

    public function testObservationInputIsReindexedAsAList(): void
    {
        $first = new DiagnosticObservationSnapshot('execution-started', null, null, null);
        $snapshot = new DiagnosticBatchSnapshot(
            'execution-123',
            'http-request',
            [5 => $first],
            0,
        );

        self::assertSame([$first], $snapshot->observations());
    }

    public function testDiagnosticEntryOrderAttributesAndDroppedCountArePreserved(): void
    {
        $entry = new DiagnosticEntrySnapshot(
            'database',
            'query',
            [
                new DiagnosticEntryAttributeSnapshot('statement', 'select-user'),
                new DiagnosticEntryAttributeSnapshot('duration_ms', 12),
                new DiagnosticEntryAttributeSnapshot('sample_rate', 1.0),
                new DiagnosticEntryAttributeSnapshot('cached', false),
                new DiagnosticEntryAttributeSnapshot('tenant', null),
            ],
        );

        $snapshot = new DiagnosticBatchSnapshot(
            'execution-123',
            'http-request',
            [],
            0,
            [$entry],
            3,
        );

        self::assertSame([$entry], $snapshot->diagnosticEntries());
        self::assertSame(3, $snapshot->droppedDiagnosticEntryCount());
    }

    public function testDiagnosticEntryCollectionsDoNotExposeMutableInternalState(): void
    {
        $entry = new DiagnosticEntrySnapshot('database', 'query', []);
        $snapshot = new DiagnosticBatchSnapshot(
            'execution-123',
            'http-request',
            [],
            0,
            [$entry],
            0,
        );

        $entries = $snapshot->diagnosticEntries();
        $entries[] = new DiagnosticEntrySnapshot('cache', 'hit', []);

        self::assertSame([$entry], $snapshot->diagnosticEntries());
    }

    public function testOversizedDiagnosticEntryCategoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic entry category is too long.');

        new DiagnosticEntrySnapshot(str_repeat('c', DiagnosticEntry::MAX_CATEGORY_LENGTH + 1), 'query', []);
    }

    public function testOversizedDiagnosticEntryNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic entry name is too long.');

        new DiagnosticEntrySnapshot('database', str_repeat('n', DiagnosticEntry::MAX_NAME_LENGTH + 1), []);
    }

    public function testExcessiveDiagnosticEntryAttributeCountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic entry attribute count is too large.');

        new DiagnosticEntrySnapshot(
            'database',
            'query',
            array_fill(
                0,
                DiagnosticEntry::MAX_ATTRIBUTE_COUNT + 1,
                new DiagnosticEntryAttributeSnapshot('attribute', 'value'),
            ),
        );
    }

    public function testInvalidDiagnosticEntryAttributeObjectIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic entry snapshot attributes must be diagnostic entry attribute snapshots.');

        new DiagnosticEntrySnapshot('database', 'query', [new \stdClass()]);
    }

    public function testOversizedDiagnosticEntryAttributeNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic entry attribute name is too long.');

        new DiagnosticEntryAttributeSnapshot(str_repeat('a', DiagnosticAttribute::MAX_NAME_LENGTH + 1), 'value');
    }

    public function testOversizedDiagnosticEntryAttributeStringValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic entry attribute string value is too long.');

        new DiagnosticEntryAttributeSnapshot('payload', str_repeat('v', DiagnosticAttribute::MAX_STRING_VALUE_LENGTH + 1));
    }

    public function testNonFiniteDiagnosticEntryAttributeFloatValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic entry attribute float value must be finite.');

        new DiagnosticEntryAttributeSnapshot('duration', INF);
    }

    public function testNegativeDroppedDiagnosticEntryCountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dropped diagnostic entry count must not be negative.');

        new DiagnosticBatchSnapshot('execution-123', 'http-request', [], 0, [], -1);
    }

    public function testNegativeDroppedCountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dropped observation count must not be negative.');

        new DiagnosticBatchSnapshot('execution-123', 'http-request', [], -1);
    }

    public function testEmptyIdentifierIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Execution identifier must not be empty.');

        new DiagnosticBatchSnapshot('', 'http-request', [], 0);
    }
}
