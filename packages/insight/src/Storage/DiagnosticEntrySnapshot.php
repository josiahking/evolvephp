<?php

declare(strict_types=1);

namespace Evolve\Insight\Storage;

use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticEntry;

final class DiagnosticEntrySnapshot
{
    /**
     * @var list<DiagnosticEntryAttributeSnapshot>
     */
    private array $attributes;

    /**
     * @param array<array-key, mixed> $attributes
     */
    public function __construct(
        private string $category,
        private string $name,
        array $attributes,
    ) {
        DiagnosticAttribute::assertBoundedNonEmptyString(
            $this->category,
            'Diagnostic entry category',
            DiagnosticEntry::MAX_CATEGORY_LENGTH,
        );

        DiagnosticAttribute::assertBoundedNonEmptyString(
            $this->name,
            'Diagnostic entry name',
            DiagnosticEntry::MAX_NAME_LENGTH,
        );

        if (count($attributes) > DiagnosticEntry::MAX_ATTRIBUTE_COUNT) {
            throw new \InvalidArgumentException('Diagnostic entry attribute count is too large.');
        }

        $validatedAttributes = array();

        foreach ($attributes as $attribute) {
            if (!$attribute instanceof DiagnosticEntryAttributeSnapshot) {
                throw new \InvalidArgumentException('Diagnostic entry snapshot attributes must be diagnostic entry attribute snapshots.');
            }

            $validatedAttributes[] = $attribute;
        }

        $this->attributes = $validatedAttributes;
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
     * @return list<DiagnosticEntryAttributeSnapshot>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
