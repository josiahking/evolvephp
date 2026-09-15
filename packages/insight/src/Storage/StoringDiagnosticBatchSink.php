<?php

declare(strict_types=1);

namespace Evolve\Insight\Storage;

use Evolve\Insight\DiagnosticBatch;
use Evolve\Insight\DiagnosticBatchSink;

final readonly class StoringDiagnosticBatchSink implements DiagnosticBatchSink
{
    public function __construct(
        private DiagnosticBatchProjector $projector,
        private DiagnosticBatchStore $store,
    ) {}

    public function accept(DiagnosticBatch $batch): void
    {
        $this->store->save($this->projector->project($batch));
    }
}
