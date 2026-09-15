<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Storage;

use Evolve\Insight\Storage\DiagnosticBatchSnapshot;
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
