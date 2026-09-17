<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit;

use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\DiagnosticPipeline;
use Evolve\Insight\Storage\DiagnosticBatchSnapshot;
use Evolve\Insight\Storage\DiagnosticBatchStore;
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

    private function observation(ObservationType $type, ExecutionIdentifier $identifier): Observation
    {
        return new Observation($type, $identifier, ExecutionKind::HttpRequest);
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
