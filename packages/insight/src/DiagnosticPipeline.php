<?php

declare(strict_types=1);

namespace Evolve\Insight;

use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationSink;
use Evolve\Insight\Capture\DiagnosticCapturePolicy;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\Storage\DiagnosticBatchProjector;
use Evolve\Insight\Storage\DiagnosticBatchStore;
use Evolve\Insight\Storage\StoringDiagnosticBatchSink;

final readonly class DiagnosticPipeline implements ObservationSink
{
    public function __construct(private DiagnosticBatchCollector $collector) {}

    public static function storing(
        DiagnosticBatchStore $store,
        int $maximumRetainedObservationCount,
        int $maximumRetainedDiagnosticEntryCount,
        ?DiagnosticCapturePolicy $capturePolicy = null,
        ?DiagnosticBatchProjector $projector = null,
    ): self {
        return self::collecting(
            new StoringDiagnosticBatchSink($projector ?? new DiagnosticBatchProjector(), $store),
            $maximumRetainedObservationCount,
            $maximumRetainedDiagnosticEntryCount,
            $capturePolicy,
        );
    }

    public static function collecting(
        DiagnosticBatchSink $sink,
        int $maximumRetainedObservationCount,
        int $maximumRetainedDiagnosticEntryCount,
        ?DiagnosticCapturePolicy $capturePolicy = null,
    ): self {
        return new self(new DiagnosticBatchCollector(
            $sink,
            $maximumRetainedObservationCount,
            $capturePolicy,
            $maximumRetainedDiagnosticEntryCount,
        ));
    }

    public function observe(Observation $observation): void
    {
        $this->collector->observe($observation);
    }

    public function capture(DiagnosticEntry $candidate): void
    {
        $this->collector->capture($candidate);
    }
}
