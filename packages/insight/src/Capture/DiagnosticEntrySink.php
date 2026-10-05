<?php

declare(strict_types=1);

namespace Evolve\Insight\Capture;

interface DiagnosticEntrySink
{
    public function capture(DiagnosticEntry $candidate): void;
}
