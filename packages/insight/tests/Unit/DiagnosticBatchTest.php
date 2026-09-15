<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit;

use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Insight\DiagnosticBatch;
use PHPUnit\Framework\TestCase;

final class DiagnosticBatchTest extends TestCase
{
    public function testItExposesImmutableExecutionIdentityKindObservationsAndDropCount(): void
    {
        $identifier = ExecutionIdentifier::generate();
        $started = $this->observation(ObservationType::ExecutionStarted, $identifier, ExecutionKind::HttpRequest);
        $completed = $this->observation(ObservationType::ExecutionCompleted, $identifier, ExecutionKind::HttpRequest);
        $observations = array($started, $completed);

        $batch = new DiagnosticBatch($identifier, ExecutionKind::HttpRequest, $observations, 3);
        $observations[] = $this->observation(ObservationType::HandlerCompleted, $identifier, ExecutionKind::HttpRequest);

        self::assertSame($identifier, $batch->identifier());
        self::assertSame(ExecutionKind::HttpRequest, $batch->kind());
        self::assertSame(array($started, $completed), $batch->observations());
        self::assertSame(3, $batch->droppedObservationCount());
    }

    public function testItRejectsNegativeDroppedObservationCounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dropped observation count must not be negative.');

        new DiagnosticBatch(
            ExecutionIdentifier::generate(),
            ExecutionKind::CliCommand,
            array(),
            -1,
        );
    }

    private function observation(
        ObservationType $type,
        ExecutionIdentifier $identifier,
        ExecutionKind $kind,
    ): Observation {
        return new Observation($type, $identifier, $kind);
    }
}
