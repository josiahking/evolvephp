<?php

declare(strict_types=1);

namespace Evolve\Insight\Capture;

final class DiagnosticEntry
{
    public const int MAX_EXECUTION_IDENTIFIER_LENGTH = 128;
    public const int MAX_CATEGORY_LENGTH = 128;
    public const int MAX_NAME_LENGTH = 128;
    public const int MAX_ATTRIBUTE_COUNT = 64;

    /**
     * @var list<DiagnosticAttribute>
     */
    private array $attributes;

    /**
     * @param array<array-key, mixed> $attributes
     */
    public function __construct(
        private string $executionIdentifier,
        private string $category,
        private string $name,
        array $attributes,
    ) {
        DiagnosticAttribute::assertBoundedNonEmptyString(
            $this->executionIdentifier,
            'Diagnostic execution identifier',
            self::MAX_EXECUTION_IDENTIFIER_LENGTH,
        );
        DiagnosticAttribute::assertBoundedNonEmptyString($this->category, 'Diagnostic category', self::MAX_CATEGORY_LENGTH);
        DiagnosticAttribute::assertBoundedNonEmptyString($this->name, 'Diagnostic name', self::MAX_NAME_LENGTH);

        if (count($attributes) > self::MAX_ATTRIBUTE_COUNT) {
            throw new \InvalidArgumentException('Diagnostic entry attribute count is too large.');
        }

        $validatedAttributes = array();

        foreach ($attributes as $attribute) {
            if (!$attribute instanceof DiagnosticAttribute) {
                throw new \InvalidArgumentException('Diagnostic entry attributes must be diagnostic attributes.');
            }

            $validatedAttributes[] = $attribute;
        }

        $this->attributes = $validatedAttributes;
    }

    public function executionIdentifier(): string
    {
        return $this->executionIdentifier;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<DiagnosticAttribute>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
