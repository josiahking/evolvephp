<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit;

use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Insight\Capture\DefaultDiagnosticRedactor;
use Evolve\Insight\Capture\DeterministicDiagnosticSampler;
use Evolve\Insight\Capture\DiagnosticCaptureFilter;
use Evolve\Insight\Capture\DiagnosticCapturePolicy;
use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\DiagnosticPipeline;
use Evolve\Insight\Storage\DiagnosticBatchSnapshot;
use Evolve\Insight\Storage\DiagnosticBatchStore;
use Evolve\Insight\Storage\DiagnosticEntryAttributeSnapshot;
use Evolve\Insight\Watcher\ObservationDiagnosticWatcher;
use PHPUnit\Framework\TestCase;

final class DiagnosticPipelineTest extends TestCase
{
    public function testItRejectsNonPositiveObservationLimitDuringComposition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum retained observation count must be positive.');

        DiagnosticPipeline::storing(new RecordingSnapshotStore(), 0, 1);
    }

    public function testItRejectsNonPositiveDiagnosticEntryLimitDuringComposition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum retained diagnostic entry count must be positive.');

        DiagnosticPipeline::storing(new RecordingSnapshotStore(), 1, 0);
    }

    public function testStoringCompositionIsExplicitAndImplementsObservationSink(): void
    {
        $store = new RecordingSnapshotStore();
        $pipeline = DiagnosticPipeline::storing($store, 5, 5);
        $identifier = ExecutionIdentifier::generate();

        self::assertSame(array(), $store->saved);

        $pipeline->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $pipeline->capture(new DiagnosticEntry(
            $identifier->value(),
            'database',
            'query',
            array(new DiagnosticAttribute(
                'statement',
                DiagnosticDataClassification::PublicOperationalMetadata,
                'select-user',
            )),
        ));
        $pipeline->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));

        $saved = $store->latest(10);

        self::assertCount(1, $saved);
        self::assertSame($identifier->value(), $saved[0]->executionIdentifier());
        self::assertSame('database', $saved[0]->diagnosticEntries()[0]->category());
    }

    public function testZeroWatcherCompositionPreservesBackwardCompatibility(): void
    {
        $store = new RecordingSnapshotStore();
        $pipeline = DiagnosticPipeline::storing($store, 5, 5, observationWatchers: array());
        $identifier = ExecutionIdentifier::generate();

        $pipeline->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $pipeline->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));

        self::assertCount(1, $store->saved);
        self::assertSame(array(), $store->saved[0]->diagnosticEntries());
    }

    public function testItRejectsInvalidWatcherConfigurationDuringComposition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Observation diagnostic watchers must be observation diagnostic watchers.');

        DiagnosticPipeline::storing(new RecordingSnapshotStore(), 5, 5, observationWatchers: array(new \stdClass()));
    }

    public function testConfiguredWatchersAndProducedEntriesKeepDeterministicOrder(): void
    {
        $store = new RecordingSnapshotStore();
        $pipeline = DiagnosticPipeline::storing(
            $store,
            5,
            6,
            observationWatchers: array(
                new StaticObservationWatcher('first', array('one', 'two')),
                new StaticObservationWatcher('second', array('three')),
            ),
        );
        $identifier = ExecutionIdentifier::generate();

        $pipeline->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $pipeline->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));

        self::assertSame(
            array('one', 'two', 'three', 'one', 'two', 'three'),
            array_map(static fn ($entry): string => $entry->name(), $store->saved[0]->diagnosticEntries()),
        );
    }

    public function testExecutionStartedOpensBatchBeforeWatcherCandidatesAreCaptured(): void
    {
        $store = new RecordingSnapshotStore();
        $pipeline = DiagnosticPipeline::storing(
            $store,
            5,
            5,
            observationWatchers: array(new StaticObservationWatcher('execution', array('started-candidate'))),
        );
        $identifier = ExecutionIdentifier::generate();

        $pipeline->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $pipeline->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));

        self::assertSame('started-candidate', $store->saved[0]->diagnosticEntries()[0]->name());
    }

    public function testExecutionCompletedCapturesWatcherCandidatesBeforeFinalization(): void
    {
        $store = new RecordingSnapshotStore();
        $pipeline = DiagnosticPipeline::storing(
            $store,
            5,
            5,
            observationWatchers: array(new CompletionOnlyWatcher()),
        );
        $identifier = ExecutionIdentifier::generate();

        $pipeline->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $pipeline->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));

        self::assertSame('completion-candidate', $store->saved[0]->diagnosticEntries()[0]->name());
    }

    public function testMismatchedWatcherExecutionIdentifierIsRejectedWithoutCrossExecutionCapture(): void
    {
        $store = new RecordingSnapshotStore();
        $first = ExecutionIdentifier::generate();
        $second = ExecutionIdentifier::generate();
        $pipeline = DiagnosticPipeline::storing(
            $store,
            5,
            5,
            observationWatchers: array(new HandlerMismatchedObservationWatcher($second->value())),
        );

        $pipeline->observe($this->observation(ObservationType::ExecutionStarted, $first));
        $pipeline->observe($this->observation(ObservationType::ExecutionStarted, $second, ExecutionKind::QueueMessage));

        try {
            $pipeline->observe($this->observation(ObservationType::HandlerCompleted, $first));
            self::fail('Expected mismatched watcher output to fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Observation diagnostic watcher returned an entry for a different execution identifier.', $exception->getMessage());
        }

        $pipeline->observe($this->observation(ObservationType::ExecutionCompleted, $second, ExecutionKind::QueueMessage));

        self::assertCount(1, $store->saved);
        self::assertSame($second->value(), $store->saved[0]->executionIdentifier());
        self::assertSame(array(), $store->saved[0]->diagnosticEntries());
    }

    public function testWatcherCandidatesFlowThroughCapturePolicyAndCapacityAccounting(): void
    {
        $store = new RecordingSnapshotStore();
        $pipeline = DiagnosticPipeline::storing(
            $store,
            5,
            1,
            new DiagnosticCapturePolicy(
                filter: new DiagnosticCaptureFilter(disabledNames: array('filtered')),
                sampler: new DeterministicDiagnosticSampler(100),
            ),
            observationWatchers: array(new PolicyExerciseWatcher()),
        );
        $identifier = ExecutionIdentifier::generate();

        $pipeline->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $pipeline->observe($this->observation(ObservationType::HandlerCompleted, $identifier));
        $pipeline->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));

        self::assertSame(array('accepted'), array_map(static fn ($entry): string => $entry->name(), $store->saved[0]->diagnosticEntries()));
        self::assertSame(1, $store->saved[0]->droppedDiagnosticEntryCount());
    }

    public function testWatcherCandidatesFlowThroughDefaultRedaction(): void
    {
        $store = new RecordingSnapshotStore();
        $pipeline = DiagnosticPipeline::storing(
            $store,
            5,
            5,
            observationWatchers: array(new SensitiveOperationalAttributeWatcher()),
        );
        $identifier = ExecutionIdentifier::generate();

        $pipeline->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $pipeline->observe($this->observation(ObservationType::HandlerCompleted, $identifier));
        $pipeline->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));

        $entries = $store->saved[0]->diagnosticEntries();

        self::assertCount(1, $entries);
        self::assertSame('redaction-candidate', $entries[0]->name());
        self::assertSame(
            array(
                'authorization' => DefaultDiagnosticRedactor::REDACTION_MARKER,
                'kind' => 'http-request',
            ),
            $this->snapshotAttributeValues($entries[0]->attributes()),
        );
        self::assertStringNotContainsString(
            'Bearer original-sensitive-value',
            json_encode($this->snapshotAttributeValues($entries[0]->attributes()), JSON_THROW_ON_ERROR),
        );
        self::assertSame(0, $store->saved[0]->droppedDiagnosticEntryCount());
    }

    public function testWatcherCandidatesFlowThroughDeterministicSampling(): void
    {
        $store = new RecordingSnapshotStore();
        $pipeline = DiagnosticPipeline::storing(
            $store,
            5,
            5,
            new DiagnosticCapturePolicy(sampler: new DeterministicDiagnosticSampler(0)),
            observationWatchers: array(new HandlerDiagnosticWatcher('sampled-out')),
        );
        $identifier = ExecutionIdentifier::generate();

        $pipeline->observe($this->observation(ObservationType::ExecutionStarted, $identifier));
        $pipeline->observe($this->observation(ObservationType::HandlerCompleted, $identifier));
        $pipeline->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));

        self::assertSame(array(), $store->saved[0]->diagnosticEntries());
        self::assertSame(0, $store->saved[0]->droppedDiagnosticEntryCount());
    }

    public function testInterleavedActiveExecutionsKeepWatcherDiagnosticsIsolated(): void
    {
        $store = new RecordingSnapshotStore();
        $pipeline = DiagnosticPipeline::storing(
            $store,
            5,
            5,
            observationWatchers: array(new HandlerDiagnosticWatcher('handler-produced')),
        );
        $first = ExecutionIdentifier::generate();
        $second = ExecutionIdentifier::generate();

        $pipeline->observe($this->observation(ObservationType::ExecutionStarted, $first, ExecutionKind::HttpRequest));
        $pipeline->observe($this->observation(ObservationType::ExecutionStarted, $second, ExecutionKind::QueueMessage));
        $pipeline->observe($this->observation(ObservationType::HandlerCompleted, $first, ExecutionKind::HttpRequest));
        $pipeline->observe($this->observation(ObservationType::HandlerCompleted, $second, ExecutionKind::QueueMessage));
        $pipeline->observe($this->observation(ObservationType::ExecutionCompleted, $second, ExecutionKind::QueueMessage));
        $pipeline->observe($this->observation(ObservationType::ExecutionCompleted, $first, ExecutionKind::HttpRequest));

        $firstSnapshot = $store->find($first->value());
        $secondSnapshot = $store->find($second->value());

        self::assertNotNull($firstSnapshot);
        self::assertNotNull($secondSnapshot);
        self::assertSame($first->value(), $firstSnapshot->executionIdentifier());
        self::assertSame($second->value(), $secondSnapshot->executionIdentifier());
        self::assertSame(array('http-request'), $this->snapshotEntryAttributeValues($firstSnapshot, 'kind'));
        self::assertSame(array('queue-message'), $this->snapshotEntryAttributeValues($secondSnapshot, 'kind'));
    }

    public function testWatcherFailureDuringCompletionStillFinalizesAndDoesNotResurrectState(): void
    {
        $store = new RecordingSnapshotStore();
        $pipeline = DiagnosticPipeline::storing(
            $store,
            5,
            5,
            observationWatchers: array(new ThrowingCompletionWatcher()),
        );
        $identifier = ExecutionIdentifier::generate();

        $pipeline->observe($this->observation(ObservationType::ExecutionStarted, $identifier));

        try {
            $pipeline->observe($this->observation(ObservationType::ExecutionCompleted, $identifier));
            self::fail('Expected watcher failure to propagate after completion finalization.');
        } catch (\RuntimeException $exception) {
            self::assertSame('watcher failed', $exception->getMessage());
        }

        $pipeline->capture(new DiagnosticEntry(
            $identifier->value(),
            'runtime',
            'late',
            array(new DiagnosticAttribute('kind', DiagnosticDataClassification::PublicOperationalMetadata, 'ignored')),
        ));
        $pipeline->observe($this->observation(ObservationType::ExecutionStarted, $identifier));

        self::assertCount(1, $store->saved);
        self::assertSame(array(), $store->saved[0]->diagnosticEntries());
    }

    private function observation(
        ObservationType $type,
        ExecutionIdentifier $identifier,
        ExecutionKind $kind = ExecutionKind::HttpRequest,
    ): Observation
    {
        return new Observation($type, $identifier, $kind);
    }

    /**
     * @param list<DiagnosticEntryAttributeSnapshot> $attributes
     *
     * @return array<string, string|int|float|bool|null>
     */
    private function snapshotAttributeValues(array $attributes): array
    {
        $values = array();

        foreach ($attributes as $attribute) {
            $values[$attribute->name()] = $attribute->value();
        }

        return $values;
    }

    /**
     * @return list<string|int|float|bool|null>
     */
    private function snapshotEntryAttributeValues(DiagnosticBatchSnapshot $snapshot, string $attributeName): array
    {
        $values = array();

        foreach ($snapshot->diagnosticEntries() as $entry) {
            foreach ($entry->attributes() as $attribute) {
                if ($attribute->name() === $attributeName) {
                    $values[] = $attribute->value();
                }
            }
        }

        return $values;
    }
}

final class StaticObservationWatcher implements ObservationDiagnosticWatcher
{
    /**
     * @param list<string> $names
     */
    public function __construct(private string $category, private array $names) {}

    public function watch(Observation $observation): array
    {
        return array_map(
            fn (string $name): DiagnosticEntry => new DiagnosticEntry(
                $observation->identifier()->value(),
                $this->category,
                $name,
                array(new DiagnosticAttribute('kind', DiagnosticDataClassification::PublicOperationalMetadata, $observation->kind()->value)),
            ),
            $this->names,
        );
    }
}

final class CompletionOnlyWatcher implements ObservationDiagnosticWatcher
{
    public function watch(Observation $observation): array
    {
        if ($observation->type() !== ObservationType::ExecutionCompleted) {
            return array();
        }

        return array(new DiagnosticEntry(
            $observation->identifier()->value(),
            'execution',
            'completion-candidate',
            array(new DiagnosticAttribute('kind', DiagnosticDataClassification::PublicOperationalMetadata, $observation->kind()->value)),
        ));
    }
}

final class HandlerMismatchedObservationWatcher implements ObservationDiagnosticWatcher
{
    public function __construct(private string $executionIdentifier) {}

    public function watch(Observation $observation): array
    {
        if ($observation->type() !== ObservationType::HandlerCompleted) {
            return array();
        }

        return array(new DiagnosticEntry(
            $this->executionIdentifier,
            'execution',
            'mismatched',
            array(new DiagnosticAttribute('kind', DiagnosticDataClassification::PublicOperationalMetadata, $observation->kind()->value)),
        ));
    }
}

final class PolicyExerciseWatcher implements ObservationDiagnosticWatcher
{
    public function watch(Observation $observation): array
    {
        if ($observation->type() !== ObservationType::HandlerCompleted) {
            return array();
        }

        return array(
            new DiagnosticEntry(
                $observation->identifier()->value(),
                'runtime',
                'filtered',
                array(new DiagnosticAttribute('kind', DiagnosticDataClassification::PublicOperationalMetadata, $observation->kind()->value)),
            ),
            new DiagnosticEntry(
                $observation->identifier()->value(),
                'runtime',
                'suppressed',
                array(new DiagnosticAttribute('payload', DiagnosticDataClassification::PersonalData, 'person@example.test')),
            ),
            new DiagnosticEntry(
                $observation->identifier()->value(),
                'runtime',
                'accepted',
                array(new DiagnosticAttribute('kind', DiagnosticDataClassification::PublicOperationalMetadata, $observation->kind()->value)),
            ),
            new DiagnosticEntry(
                $observation->identifier()->value(),
                'runtime',
                'over-capacity',
                array(new DiagnosticAttribute('kind', DiagnosticDataClassification::PublicOperationalMetadata, $observation->kind()->value)),
            ),
        );
    }
}

final class SensitiveOperationalAttributeWatcher implements ObservationDiagnosticWatcher
{
    public function watch(Observation $observation): array
    {
        if ($observation->type() !== ObservationType::HandlerCompleted) {
            return array();
        }

        return array(new DiagnosticEntry(
            $observation->identifier()->value(),
            'runtime',
            'redaction-candidate',
            array(
                new DiagnosticAttribute(
                    'authorization',
                    DiagnosticDataClassification::PublicOperationalMetadata,
                    'Bearer original-sensitive-value',
                ),
                new DiagnosticAttribute('kind', DiagnosticDataClassification::PublicOperationalMetadata, $observation->kind()->value),
            ),
        ));
    }
}

final class HandlerDiagnosticWatcher implements ObservationDiagnosticWatcher
{
    public function __construct(private string $name) {}

    public function watch(Observation $observation): array
    {
        if ($observation->type() !== ObservationType::HandlerCompleted) {
            return array();
        }

        return array(new DiagnosticEntry(
            $observation->identifier()->value(),
            'runtime',
            $this->name,
            array(new DiagnosticAttribute('kind', DiagnosticDataClassification::PublicOperationalMetadata, $observation->kind()->value)),
        ));
    }
}

final class ThrowingCompletionWatcher implements ObservationDiagnosticWatcher
{
    public function watch(Observation $observation): array
    {
        if ($observation->type() === ObservationType::ExecutionCompleted) {
            throw new \RuntimeException('watcher failed');
        }

        return array();
    }
}

final class RecordingSnapshotStore implements DiagnosticBatchStore
{
    /**
     * @var list<DiagnosticBatchSnapshot>
     */
    public array $saved = array();

    public function save(DiagnosticBatchSnapshot $snapshot): void
    {
        $this->saved[] = $snapshot;
    }

    public function find(string $executionIdentifier): ?DiagnosticBatchSnapshot
    {
        foreach ($this->saved as $snapshot) {
            if ($snapshot->executionIdentifier() === $executionIdentifier) {
                return $snapshot;
            }
        }

        return null;
    }

    public function latest(int $limit): array
    {
        return array_slice(array_reverse($this->saved), 0, $limit);
    }
}
