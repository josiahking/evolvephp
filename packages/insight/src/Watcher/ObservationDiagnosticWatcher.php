<?php

declare(strict_types=1);

namespace Evolve\Insight\Watcher;

use Evolve\Core\Instrumentation\Observation;
use Evolve\Insight\Capture\DiagnosticEntry;

interface ObservationDiagnosticWatcher
{
    /**
     * @return list<DiagnosticEntry>
     */
    public function watch(Observation $observation): array;
}
