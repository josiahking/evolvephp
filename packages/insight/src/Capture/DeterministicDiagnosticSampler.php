<?php

declare(strict_types=1);

namespace Evolve\Insight\Capture;

final class DeterministicDiagnosticSampler
{
    public function __construct(private int $percentage)
    {
        if ($this->percentage < 0 || $this->percentage > 100) {
            throw new \InvalidArgumentException('Diagnostic sampler percentage must be between 0 and 100.');
        }
    }

    public function accepts(string $executionIdentifier): bool
    {
        DiagnosticAttribute::assertBoundedNonEmptyString(
            $executionIdentifier,
            'Diagnostic execution identifier',
            DiagnosticEntry::MAX_EXECUTION_IDENTIFIER_LENGTH,
        );

        if ($this->percentage === 0) {
            return false;
        }

        if ($this->percentage === 100) {
            return true;
        }

        return ((int) sprintf('%u', crc32($executionIdentifier)) % 100) < $this->percentage;
    }
}
