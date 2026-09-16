<?php

declare(strict_types=1);

namespace Evolve\Insight\Capture;

interface DiagnosticRedactor
{
    public function redact(DiagnosticAttribute $attribute): ?DiagnosticAttribute;
}
