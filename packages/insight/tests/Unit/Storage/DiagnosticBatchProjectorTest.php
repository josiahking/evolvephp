<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Storage;

use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ProcessReuseDecision;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\DiagnosticBatch;
use Evolve\Insight\Storage\DiagnosticBatchProjector;
use Evolve\Insight\Storage\DiagnosticEntryAttributeSnapshot;
use Evolve\Insight\Storage\DiagnosticEntrySnapshot;
use Evolve\Insight\Storage\DiagnosticObservationSnapshot;
use PHPUnit\Framework\TestCase;

final class DiagnosticBatchProjectorTest extends TestCase
{
    public function testItProjectsExecutionIdentityKindObservationOrderAndDroppedCount(): void
    {
        $identifier = ExecutionIdentifier::generate();
        $first = new Observation(ObservationType::ExecutionStarted, $identifier, ExecutionKind::CliCommand);
        $second = new Observation(
            ObservationType::ExecutionCompleted,
            $identifier,
            ExecutionKind::CliCommand,
            ObservationOutcome::Succeeded,
            null,
            ProcessReuseDecision::Reusable,
        );
        $batch = new DiagnosticBatch($identifier, ExecutionKind::CliCommand, array($first, $second), 3);

        $snapshot = (new DiagnosticBatchProjector())->project($batch);

        self::assertSame($identifier->value(), $snapshot->executionIdentifier());
        self::assertSame('cli-command', $snapshot->executionKind());
        self::assertSame(3, $snapshot->droppedObservationCount());
        self::assertEquals(
            array(
                new DiagnosticObservationSnapshot('execution-started', null, null, null),
                new DiagnosticObservationSnapshot('execution-completed', 'succeeded', null, 'reusable'),
            ),
            $snapshot->observations(),
        );
    }

    public function testEveryCurrentObservationTypeCanBeProjected(): void
    {
        $identifier = ExecutionIdentifier::generate();
        $batch = new DiagnosticBatch(
            $identifier,
            ExecutionKind::WorkerTask,
            array_map(
                static fn (ObservationType $type): Observation => new Observation($type, $identifier, ExecutionKind::WorkerTask),
                ObservationType::cases(),
            ),
            0,
        );

        $snapshot = (new DiagnosticBatchProjector())->project($batch);

        self::assertSame(
            array(
                'execution-started',
                'handler-completed',
                'scope-close-started',
                'scope-close-completed',
                'quarantine-required',
                'execution-completed',
            ),
            array_map(
                static fn (DiagnosticObservationSnapshot $observation): string => $observation->type(),
                $snapshot->observations(),
            ),
        );
    }

    public function testOutcomeErrorTypeAndReuseDecisionConversionsRemainNullableWhenAbsent(): void
    {
        $identifier = ExecutionIdentifier::generate();
        $batch = new DiagnosticBatch(
            $identifier,
            ExecutionKind::HttpRequest,
            array(new Observation(ObservationType::HandlerCompleted, $identifier, ExecutionKind::HttpRequest)),
            0,
        );

        $observation = (new DiagnosticBatchProjector())->project($batch)->observations()[0];

        self::assertNull($observation->outcome());
        self::assertNull($observation->errorType());
        self::assertNull($observation->reuseDecision());
    }

    public function testOutcomeErrorTypeAndReuseDecisionConversionsUseStableStrings(): void
    {
        $identifier = ExecutionIdentifier::generate();
        $batch = new DiagnosticBatch(
            $identifier,
            ExecutionKind::HttpRequest,
            array(
                new Observation(
                    ObservationType::HandlerCompleted,
                    $identifier,
                    ExecutionKind::HttpRequest,
                    ObservationOutcome::Failed,
                    'LogicException',
                    ProcessReuseDecision::QuarantineRequired,
                ),
            ),
            0,
        );

        $observation = (new DiagnosticBatchProjector())->project($batch)->observations()[0];

        self::assertSame('failed', $observation->outcome());
        self::assertSame('LogicException', $observation->errorType());
        self::assertSame('quarantine-required', $observation->reuseDecision());
    }

    public function testItProjectsDiagnosticEntriesIntoDetachedPrimitiveSnapshots(): void
    {
        $identifier = ExecutionIdentifier::generate();
        $entry = new DiagnosticEntry(
            $identifier->value(),
            'database',
            'query',
            array(
                new DiagnosticAttribute('statement', DiagnosticDataClassification::PublicOperationalMetadata, 'select-user'),
                new DiagnosticAttribute('duration_ms', DiagnosticDataClassification::InternalOperationalMetadata, 12),
                new DiagnosticAttribute('sample_rate', DiagnosticDataClassification::InternalOperationalMetadata, 1.0),
                new DiagnosticAttribute('cached', DiagnosticDataClassification::InternalOperationalMetadata, false),
                new DiagnosticAttribute('tenant', DiagnosticDataClassification::InternalOperationalMetadata, null),
            ),
        );
        $batch = new DiagnosticBatch(
            $identifier,
            ExecutionKind::HttpRequest,
            array(new Observation(ObservationType::ExecutionStarted, $identifier, ExecutionKind::HttpRequest)),
            0,
            array($entry),
            2,
        );

        $snapshot = (new DiagnosticBatchProjector())->project($batch);

        self::assertSame(2, $snapshot->droppedDiagnosticEntryCount());
        self::assertEquals(
            array(
                new DiagnosticEntrySnapshot(
                    'database',
                    'query',
                    array(
                        new DiagnosticEntryAttributeSnapshot('statement', 'select-user'),
                        new DiagnosticEntryAttributeSnapshot('duration_ms', 12),
                        new DiagnosticEntryAttributeSnapshot('sample_rate', 1.0),
                        new DiagnosticEntryAttributeSnapshot('cached', false),
                        new DiagnosticEntryAttributeSnapshot('tenant', null),
                    ),
                ),
            ),
            $snapshot->diagnosticEntries(),
        );
    }

    public function testSnapshotApiDoesNotExposeSourceCoreObjects(): void
    {
        $identifier = ExecutionIdentifier::generate();
        $batch = new DiagnosticBatch(
            $identifier,
            ExecutionKind::QueueMessage,
            array(new Observation(ObservationType::ExecutionStarted, $identifier, ExecutionKind::QueueMessage)),
            0,
        );

        $snapshot = (new DiagnosticBatchProjector())->project($batch);

        self::assertContainsOnlyInstancesOf(DiagnosticObservationSnapshot::class, $snapshot->observations());
        self::assertSame(
            array('diagnosticEntries', 'droppedDiagnosticEntryCount', 'droppedObservationCount', 'executionIdentifier', 'executionKind', 'observations'),
            $this->publicMethods($snapshot),
        );
    }

    public function testSnapshotApiDoesNotExposeSourceDiagnosticEntryObjects(): void
    {
        $identifier = ExecutionIdentifier::generate();
        $batch = new DiagnosticBatch(
            $identifier,
            ExecutionKind::HttpRequest,
            array(),
            0,
            array(new DiagnosticEntry(
                $identifier->value(),
                'database',
                'query',
                array(new DiagnosticAttribute('statement', DiagnosticDataClassification::PublicOperationalMetadata, 'select-user')),
            )),
            0,
        );

        $snapshot = (new DiagnosticBatchProjector())->project($batch);

        self::assertContainsOnlyInstancesOf(DiagnosticEntrySnapshot::class, $snapshot->diagnosticEntries());
        self::assertContainsOnlyInstancesOf(DiagnosticEntryAttributeSnapshot::class, $snapshot->diagnosticEntries()[0]->attributes());
    }

    /**
     * @return list<string>
     */
    private function publicMethods(object $object): array
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass($object))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        $methods = array_values(array_filter(
            $methods,
            static fn (string $method): bool => $method !== '__construct',
        ));
        sort($methods);

        return $methods;
    }
}
