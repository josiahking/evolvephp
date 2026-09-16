<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Storage;

use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Insight\DiagnosticBatch;
use Evolve\Insight\DiagnosticBatchSink;
use Evolve\Insight\Storage\DiagnosticBatchProjector;
use Evolve\Insight\Storage\DiagnosticBatchSnapshot;
use Evolve\Insight\Storage\DiagnosticBatchStore;
use Evolve\Insight\Storage\InMemoryDiagnosticBatchStore;
use Evolve\Insight\Storage\StoringDiagnosticBatchSink;
use PHPUnit\Framework\TestCase;

final class StoringDiagnosticBatchSinkTest extends TestCase
{
    public function testItImplementsDiagnosticBatchSink(): void
    {
        self::assertTrue((new \ReflectionClass(StoringDiagnosticBatchSink::class))->implementsInterface(DiagnosticBatchSink::class));
    }

    public function testItProjectsThenStoresOneFinalizedBatch(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);
        $sink = new StoringDiagnosticBatchSink(new DiagnosticBatchProjector(), $store);
        $identifier = ExecutionIdentifier::generate();
        $batch = new DiagnosticBatch(
            $identifier,
            ExecutionKind::ScheduledJob,
            array(new Observation(ObservationType::ExecutionStarted, $identifier, ExecutionKind::ScheduledJob)),
            1,
        );

        $sink->accept($batch);

        self::assertEquals((new DiagnosticBatchProjector())->project($batch), $store->find($identifier->value()));
    }

    public function testStoreFailurePropagates(): void
    {
        $sink = new StoringDiagnosticBatchSink(
            new DiagnosticBatchProjector(),
            new ThrowingDiagnosticBatchStore(new \RuntimeException('store failed')),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('store failed');

        $sink->accept(new DiagnosticBatch(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest, array(), 0));
    }

    public function testProjectorStoreCollaborationDoesNotMutateOriginalBatch(): void
    {
        $store = new InMemoryDiagnosticBatchStore(10);
        $sink = new StoringDiagnosticBatchSink(new DiagnosticBatchProjector(), $store);
        $identifier = ExecutionIdentifier::generate();
        $observation = new Observation(ObservationType::ExecutionCompleted, $identifier, ExecutionKind::QueueMessage);
        $batch = new DiagnosticBatch($identifier, ExecutionKind::QueueMessage, array($observation), 4);

        $sink->accept($batch);

        self::assertSame($identifier, $batch->identifier());
        self::assertSame(ExecutionKind::QueueMessage, $batch->kind());
        self::assertSame(array($observation), $batch->observations());
        self::assertSame(4, $batch->droppedObservationCount());
    }
}

final class ThrowingDiagnosticBatchStore implements DiagnosticBatchStore
{
    public function __construct(private \Throwable $throwable) {}

    public function save(DiagnosticBatchSnapshot $snapshot): void
    {
        throw $this->throwable;
    }

    public function find(string $executionIdentifier): ?DiagnosticBatchSnapshot
    {
        return null;
    }

    public function latest(int $limit): array
    {
        return array();
    }
}
