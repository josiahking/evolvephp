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
use Evolve\Insight\Watcher\ObservationDiagnosticWatcher;

final readonly class DiagnosticPipeline implements ObservationSink
{
    /**
     * @var list<ObservationDiagnosticWatcher>
     */
    private array $observationWatchers;

    /**
     * @param array<array-key, mixed> $observationWatchers
     */
    public function __construct(
        private DiagnosticBatchCollector $collector,
        array $observationWatchers = array(),
    ) {
        $this->observationWatchers = self::validateObservationWatchers($observationWatchers);
    }

    /**
     * @param array<array-key, mixed> $observationWatchers
     */
    public static function storing(
        DiagnosticBatchStore $store,
        int $maximumRetainedObservationCount,
        int $maximumRetainedDiagnosticEntryCount,
        ?DiagnosticCapturePolicy $capturePolicy = null,
        ?DiagnosticBatchProjector $projector = null,
        array $observationWatchers = array(),
    ): self {
        return self::collecting(
            new StoringDiagnosticBatchSink($projector ?? new DiagnosticBatchProjector(), $store),
            $maximumRetainedObservationCount,
            $maximumRetainedDiagnosticEntryCount,
            $capturePolicy,
            $observationWatchers,
        );
    }

    /**
     * @param array<array-key, mixed> $observationWatchers
     */
    public static function collecting(
        DiagnosticBatchSink $sink,
        int $maximumRetainedObservationCount,
        int $maximumRetainedDiagnosticEntryCount,
        ?DiagnosticCapturePolicy $capturePolicy = null,
        array $observationWatchers = array(),
    ): self {
        return new self(new DiagnosticBatchCollector(
            $sink,
            $maximumRetainedObservationCount,
            $capturePolicy,
            $maximumRetainedDiagnosticEntryCount,
        ), $observationWatchers);
    }

    public function observe(Observation $observation): void
    {
        if ($observation->type() === \Evolve\Core\Instrumentation\ObservationType::ExecutionCompleted) {
            $failure = null;

            try {
                $this->captureWatcherEntries($observation);
            } catch (\Throwable $exception) {
                $failure = $exception;
            } finally {
                $this->collector->observe($observation);
            }

            if ($failure !== null) {
                throw $failure;
            }

            return;
        }

        $this->collector->observe($observation);
        $this->captureWatcherEntries($observation);
    }

    public function capture(DiagnosticEntry $candidate): void
    {
        $this->collector->capture($candidate);
    }

    /**
     * @param array<array-key, mixed> $watchers
     *
     * @return list<ObservationDiagnosticWatcher>
     */
    private static function validateObservationWatchers(array $watchers): array
    {
        $validated = array();

        foreach ($watchers as $watcher) {
            if (!$watcher instanceof ObservationDiagnosticWatcher) {
                throw new \InvalidArgumentException('Observation diagnostic watchers must be observation diagnostic watchers.');
            }

            $validated[] = $watcher;
        }

        return $validated;
    }

    private function captureWatcherEntries(Observation $observation): void
    {
        foreach ($this->observationWatchers as $watcher) {
            $candidates = $watcher->watch($observation);

            foreach ($candidates as $candidate) {
                if ($candidate->executionIdentifier() !== $observation->identifier()->value()) {
                    throw new \InvalidArgumentException('Observation diagnostic watcher returned an entry for a different execution identifier.');
                }
            }

            foreach ($candidates as $candidate) {
                $this->collector->capture($candidate);
            }
        }
    }
}
