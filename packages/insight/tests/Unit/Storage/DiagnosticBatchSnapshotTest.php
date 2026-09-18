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
            array($first, $second),
            2,
        );

        self::assertSame('execution-123', $snapshot->executionIdentifier());
        self::assertSame('http-request', $snapshot->executionKind());
        self::assertSame(array($first, $second), $snapshot->observations());
        self::assertSame(2, $snapshot->droppedObservationCount());
    }

    public function testObservationCollectionsDoNotExposeMutableInternalState(): void
    {
        $first = new DiagnosticObservationSnapshot('execution-started', null, null, null);
        $snapshot = new DiagnosticBatchSnapshot(
            'execution-123',
            'http-request',
            array($first),
            0,
        );

        $observations = $snapshot->observations();
        $observations[] = new DiagnosticObservationSnapshot('execution-completed', null, null, null);

        self::assertSame(array($first), $snapshot->observations());
    }

    public function testDiagnosticEntryOrderAttributesAndDroppedCountArePreserved(): void
    {
        $entry = new DiagnosticEntrySnapshot(
            'database',
            'query',
            array(
                new DiagnosticEntryAttributeSnapshot('statement', 'select-user'),
                new DiagnosticEntryAttributeSnapshot('duration_ms', 12),
                new DiagnosticEntryAttributeSnapshot('sample_rate', 1.0),
                new DiagnosticEntryAttributeSnapshot('cached', false),
                new DiagnosticEntryAttributeSnapshot('tenant', null),
            ),
        );

        $snapshot = new DiagnosticBatchSnapshot(
            'execution-123',
            'http-request',
            array(),
            0,
            array($entry),
            3,
        );

        self::assertSame(array($entry), $snapshot->diagnosticEntries());
        self::assertSame(3, $snapshot->droppedDiagnosticEntryCount());
    }

    public function testDiagnosticEntryCollectionsDoNotExposeMutableInternalState(): void
    {
        $entry = new DiagnosticEntrySnapshot('database', 'query', array());
        $snapshot = new DiagnosticBatchSnapshot(
            'execution-123',
            'http-request',
            array(),
            0,
            array($entry),
            0,
        );

        $entries = $snapshot->diagnosticEntries();
        $entries[] = new DiagnosticEntrySnapshot('cache', 'hit', array());

        self::assertSame(array($entry), $snapshot->diagnosticEntries());
    }

    public function testOversizedDiagnosticEntryCategoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic entry category is too long.');

        new DiagnosticEntrySnapshot(str_repeat('c', DiagnosticEntry::MAX_CATEGORY_LENGTH + 1), 'query', array());
    }

    public function testOversizedDiagnosticEntryNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic entry name is too long.');

        new DiagnosticEntrySnapshot('database', str_repeat('n', DiagnosticEntry::MAX_NAME_LENGTH + 1), array());
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

        new DiagnosticEntrySnapshot('database', 'query', array(new \stdClass()));
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

        new DiagnosticBatchSnapshot('execution-123', 'http-request', array(), 0, array(), -1);
    }

    public function testNegativeDroppedCountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dropped observation count must not be negative.');

        new DiagnosticBatchSnapshot('execution-123', 'http-request', array(), -1);
    }

    public function testEmptyIdentifierIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Execution identifier must not be empty.');

        new DiagnosticBatchSnapshot('', 'http-request', array(), 0);
    }
}
