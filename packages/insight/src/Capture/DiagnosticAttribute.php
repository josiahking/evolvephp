<?php

declare(strict_types=1);

namespace Evolve\Insight\Capture;

final class DiagnosticAttribute
{
    public const int MAX_NAME_LENGTH = 128;
    public const int MAX_STRING_VALUE_LENGTH = 2048;

    public function __construct(
        private string $name,
        private DiagnosticDataClassification $classification,
        private string|int|float|bool|null $value,
    ) {
        self::assertBoundedNonEmptyString($this->name, 'Diagnostic attribute name', self::MAX_NAME_LENGTH);

        if (is_float($this->value) && !is_finite($this->value)) {
            throw new \InvalidArgumentException('Diagnostic attribute float value must be finite.');
        }

        if (is_string($this->value) && strlen($this->value) > self::MAX_STRING_VALUE_LENGTH) {
            throw new \InvalidArgumentException('Diagnostic attribute string value is too long.');
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function classification(): DiagnosticDataClassification
    {
        return $this->classification;
    }

    public function value(): string|int|float|bool|null
    {
        return $this->value;
    }

    public static function assertBoundedNonEmptyString(string $value, string $field, int $maximumLength): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' must not be empty.');
        }

        if (strlen($value) > $maximumLength) {
            throw new \InvalidArgumentException($field . ' is too long.');
        }
    }
}
