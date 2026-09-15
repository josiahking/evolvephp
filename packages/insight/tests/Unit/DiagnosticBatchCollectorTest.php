<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit;

use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Insight\DiagnosticBatch;
use Evolve\Insight\DiagnosticBatchCollector;
use Evolve\Insight\DiagnosticBatchSink;
use PHPUnit\Framework\TestCase;

final class DiagnosticBatchCollectorTest extends TestCase
{
    public function testItRejectsNonPositiveMaximumRetainedObservationCounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum retained observation count must be positive.');

        new DiagnosticBatchCollector(new RecordingBatchSink(), 0);
    }

    public function testUnknownObservationsAreIgnored(): void
    {
        $sink = new RecordingBatchSink();
        $collector = new DiagnosticBatchCollector($sink, 3);

        $collector->observe($this->observation(ObservationType::HandlerCompleted));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted));

        self::assertSame(array(), $sink->batches);
    }

    public function testExecutionStartedStartsCollectionAndCompletionFinalizesOnce(): void
    {
        $sink = new RecordingBatchSink();
        $collector = new DiagnosticBatchCollector($sink, 5);
        $identifier = ExecutionIdentifier::generate();

        $collector->observe($this->observation(ObservationType::ExecutionStarted, $identifier, ExecutionKind::CliCommand));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $identifier, ExecutionKind::CliCommand));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $identifier, ExecutionKind::CliCommand));

        self::assertCount(1, $sink->batches);
        self::assertSame($identifier, $sink->batches[0]->identifier());
        self::assertSame(ExecutionKind::CliCommand, $sink->batches[0]->kind());
        self::assertSame(
            array(ObservationType::ExecutionStarted, ObservationType::ExecutionCompleted),
            $this->types($sink->batches[0]),
        );
    }

    public function testNormalLifecycleObservationsPreserveOrder(): void
    {
        $sink = new RecordingBatchSink();
        $collector = new DiagnosticBatchCollector($sink, 10);
        $identifier = ExecutionIdentifier::generate();

        foreach (array(
            ObservationType::ExecutionStarted,
            ObservationType::HandlerCompleted,
            ObservationType::ScopeCloseStarted,
            ObservationType::ScopeCloseCompleted,
            ObservationType::QuarantineRequired,
            ObservationType::ExecutionCompleted,
        ) as $type) {
            $collector->observe($this->observation($type, $identifier, ExecutionKind::WorkerTask));
        }

        self::assertSame(
            array(
                ObservationType::ExecutionStarted,
                ObservationType::HandlerCompleted,
                ObservationType::ScopeCloseStarted,
                ObservationType::ScopeCloseCompleted,
                ObservationType::QuarantineRequired,
                ObservationType::ExecutionCompleted,
            ),
            $this->types($sink->batches[0]),
        );
    }

    public function testCompletedExecutionsAreForgottenAndLaterObservationsAreIgnored(): void
    {
        $sink = new RecordingBatchSink();
        $collector = new DiagnosticBatchCollector($sink, 5);
        $identifier = ExecutionIdentifier::generate();

        $collector->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));
        $collector->observe($this->observation(ObservationType::HandlerCompleted, $identifier));

        self::assertCount(1, $sink->batches);
        self::assertSame(
            array(ObservationType::ExecutionStarted, ObservationType::ExecutionCompleted),
            $this->types($sink->batches[0]),
        );
    }

    public function testFinalizedExecutionCannotBeReplayedWithTheSameIdentifier(): void
    {
        $sink = new RecordingBatchSink();
        $collector = new DiagnosticBatchCollector($sink, 5);
        $identifier = ExecutionIdentifier::generate();

        $collector->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));
        $collector->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $collector->observe($this->observation(ObservationType::HandlerCompleted, $identifier));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));

        self::assertCount(1, $sink->batches);
        self::assertSame(
            array(ObservationType::ExecutionStarted, ObservationType::ExecutionCompleted),
            $this->types($sink->batches[0]),
        );
    }

    public function testInterleavedExecutionIdentifiersNeverMerge(): void
    {
        $sink = new RecordingBatchSink();
        $collector = new DiagnosticBatchCollector($sink, 5);
        $first = ExecutionIdentifier::generate();
        $second = ExecutionIdentifier::generate();

        $collector->observe($this->observation(ObservationType::ExecutionStarted, $first, ExecutionKind::HttpRequest));
        $collector->observe($this->observation(ObservationType::ExecutionStarted, $second, ExecutionKind::QueueMessage));
        $collector->observe($this->observation(ObservationType::HandlerCompleted, $first, ExecutionKind::HttpRequest));
        $collector->observe($this->observation(ObservationType::ScopeCloseStarted, $second, ExecutionKind::QueueMessage));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $first, ExecutionKind::HttpRequest));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $second, ExecutionKind::QueueMessage));

        self::assertCount(2, $sink->batches);
        self::assertSame($first, $sink->batches[0]->identifier());
        self::assertSame(array(ObservationType::ExecutionStarted, ObservationType::HandlerCompleted, ObservationType::ExecutionCompleted), $this->types($sink->batches[0]));
        self::assertSame($second, $sink->batches[1]->identifier());
        self::assertSame(array(ObservationType::ExecutionStarted, ObservationType::ScopeCloseStarted, ObservationType::ExecutionCompleted), $this->types($sink->batches[1]));
    }

    public function testDuplicateExecutionStartedDoesNotResetExistingState(): void
    {
        $sink = new RecordingBatchSink();
        $collector = new DiagnosticBatchCollector($sink, 5);
        $identifier = ExecutionIdentifier::generate();

        $collector->observe($this->observation(ObservationType::ExecutionStarted, $identifier, ExecutionKind::HttpRequest));
        $collector->observe($this->observation(ObservationType::HandlerCompleted, $identifier, ExecutionKind::HttpRequest));
        $collector->observe($this->observation(ObservationType::ExecutionStarted, $identifier, ExecutionKind::QueueMessage));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $identifier, ExecutionKind::QueueMessage));

        self::assertSame(ExecutionKind::HttpRequest, $sink->batches[0]->kind());
        self::assertSame(
            array(
                ObservationType::ExecutionStarted,
                ObservationType::HandlerCompleted,
                ObservationType::ExecutionStarted,
                ObservationType::ExecutionCompleted,
            ),
            $this->types($sink->batches[0]),
        );
    }

    public function testPerExecutionBoundAndDroppedObservationCountAreExact(): void
    {
        $sink = new RecordingBatchSink();
        $collector = new DiagnosticBatchCollector($sink, 2);
        $identifier = ExecutionIdentifier::generate();

        $collector->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $collector->observe($this->observation(ObservationType::HandlerCompleted, $identifier));
        $collector->observe($this->observation(ObservationType::ScopeCloseStarted, $identifier));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));

        self::assertSame(array(ObservationType::ExecutionStarted, ObservationType::HandlerCompleted), $this->types($sink->batches[0]));
        self::assertSame(2, $sink->batches[0]->droppedObservationCount());
    }

    public function testCompletionIsRetainedWhenCapacityRemains(): void
    {
        $sink = new RecordingBatchSink();
        $collector = new DiagnosticBatchCollector($sink, 2);
        $identifier = ExecutionIdentifier::generate();

        $collector->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));

        self::assertSame(array(ObservationType::ExecutionStarted, ObservationType::ExecutionCompleted), $this->types($sink->batches[0]));
        self::assertSame(0, $sink->batches[0]->droppedObservationCount());
    }

    public function testOneExecutionReachingItsBoundDoesNotAffectAnotherExecution(): void
    {
        $sink = new RecordingBatchSink();
        $collector = new DiagnosticBatchCollector($sink, 2);
        $first = ExecutionIdentifier::generate();
        $second = ExecutionIdentifier::generate();

        $collector->observe($this->observation(ObservationType::ExecutionStarted, $first));
        $collector->observe($this->observation(ObservationType::HandlerCompleted, $first));
        $collector->observe($this->observation(ObservationType::ScopeCloseStarted, $first));
        $collector->observe($this->observation(ObservationType::ExecutionStarted, $second, ExecutionKind::ScheduledJob));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $second, ExecutionKind::ScheduledJob));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $first));

        self::assertSame($second, $sink->batches[0]->identifier());
        self::assertSame(array(ObservationType::ExecutionStarted, ObservationType::ExecutionCompleted), $this->types($sink->batches[0]));
        self::assertSame(0, $sink->batches[0]->droppedObservationCount());

        self::assertSame($first, $sink->batches[1]->identifier());
        self::assertSame(array(ObservationType::ExecutionStarted, ObservationType::HandlerCompleted), $this->types($sink->batches[1]));
        self::assertSame(2, $sink->batches[1]->droppedObservationCount());
    }

    public function testCollectorRemovesActiveStateBeforeInvokingTheSink(): void
    {
        $sink = new ReentrantBatchSink();
        $collector = new DiagnosticBatchCollector($sink, 5);
        $sink->collector = $collector;
        $identifier = ExecutionIdentifier::generate();

        $collector->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));

        self::assertCount(1, $sink->batches);
    }

    public function testSinkCannotResurrectCompletedExecutionWithExecutionStartedReentry(): void
    {
        $sink = new ReentrantExecutionStartedBatchSink();
        $collector = new DiagnosticBatchCollector($sink, 5);
        $sink->collector = $collector;
        $identifier = ExecutionIdentifier::generate();

        $collector->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));

        self::assertCount(1, $sink->batches);
    }

    public function testThrowingSinkPropagatesAndDoesNotResurrectCompletedState(): void
    {
        $sink = new ThrowingBatchSink(new \RuntimeException('sink failed'));
        $collector = new DiagnosticBatchCollector($sink, 5);
        $identifier = ExecutionIdentifier::generate();

        $collector->observe($this->observation(ObservationType::ExecutionStarted, $identifier));

        try {
            $collector->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));
            self::fail('Expected sink throwable to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('sink failed', $exception->getMessage());
        }

        $collector->observe($this->observation(ObservationType::HandlerCompleted, $identifier));
        $collector->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $collector->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));

        self::assertSame(1, $sink->attempts);
    }

    /**
     * @return list<ObservationType>
     */
    private function types(DiagnosticBatch $batch): array
    {
        return array_map(static fn (Observation $observation): ObservationType => $observation->type(), $batch->observations());
    }

    private function observation(
        ObservationType $type,
        ?ExecutionIdentifier $identifier = null,
        ExecutionKind $kind = ExecutionKind::HttpRequest,
    ): Observation {
        return new Observation($type, $identifier ?? ExecutionIdentifier::generate(), $kind);
    }
}

class RecordingBatchSink implements DiagnosticBatchSink
{
    /**
     * @var list<DiagnosticBatch>
     */
    public array $batches = array();

    public function accept(DiagnosticBatch $batch): void
    {
        $this->batches[] = $batch;
    }
}

final class ReentrantBatchSink extends RecordingBatchSink
{
    public ?DiagnosticBatchCollector $collector = null;

    public function accept(DiagnosticBatch $batch): void
    {
        parent::accept($batch);

        $this->collector?->observe(new Observation(
            ObservationType::HandlerCompleted,
            $batch->identifier(),
            $batch->kind(),
        ));
    }
}

final class ReentrantExecutionStartedBatchSink extends RecordingBatchSink
{
    public ?DiagnosticBatchCollector $collector = null;

    public function accept(DiagnosticBatch $batch): void
    {
        parent::accept($batch);

        $this->collector?->observe(new Observation(
            ObservationType::ExecutionStarted,
            $batch->identifier(),
            $batch->kind(),
        ));
    }
}

final class ThrowingBatchSink implements DiagnosticBatchSink
{
    public int $attempts = 0;

    public function __construct(private \Throwable $throwable) {}

    public function accept(DiagnosticBatch $batch): void
    {
        $this->attempts++;

        throw $this->throwable;
    }
}
