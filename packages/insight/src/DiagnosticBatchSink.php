<?php

declare(strict_types=1);

namespace Evolve\Insight;

interface DiagnosticBatchSink
{
    public function accept(DiagnosticBatch $batch): void;
}
