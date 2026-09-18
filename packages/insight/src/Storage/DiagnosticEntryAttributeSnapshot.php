<?php

declare(strict_types=1);

namespace Evolve\Insight\Storage;

use Evolve\Insight\Capture\DiagnosticAttribute;

final readonly class DiagnosticEntryAttributeSnapshot
{
    public function __construct(
        private string $name,
        private string|int|float|bool|null $value,
    ) {
        DiagnosticAttribute::assertBoundedNonEmptyString(
            $this->name,
            'Diagnostic entry attribute name',
            DiagnosticAttribute::MAX_NAME_LENGTH,
        );

        if (is_float($this->value) && !is_finite($this->value)) {
            throw new \InvalidArgumentException('Diagnostic entry attribute float value must be finite.');
        }

        if (is_string($this->value) && strlen($this->value) > DiagnosticAttribute::MAX_STRING_VALUE_LENGTH) {
            throw new \InvalidArgumentException('Diagnostic entry attribute string value is too long.');
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function value(): string|int|float|bool|null
    {
        return $this->value;
    }
}
