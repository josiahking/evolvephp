<?php

declare(strict_types=1);

namespace Evolve\Insight\Capture;

final class DiagnosticCaptureFilter
{
    /**
     * @var array<string, true>
     */
    private array $disabledCategories = array();

    /**
     * @var array<string, true>
     */
    private array $disabledNames = array();

    /**
     * @param list<string> $disabledCategories
     * @param list<string> $disabledNames
     */
    public function __construct(array $disabledCategories = array(), array $disabledNames = array())
    {
        foreach ($disabledCategories as $category) {
            DiagnosticAttribute::assertBoundedNonEmptyString($category, 'Disabled diagnostic category', DiagnosticEntry::MAX_CATEGORY_LENGTH);
            $this->disabledCategories[$category] = true;
        }

        foreach ($disabledNames as $name) {
            DiagnosticAttribute::assertBoundedNonEmptyString($name, 'Disabled diagnostic name', DiagnosticEntry::MAX_NAME_LENGTH);
            $this->disabledNames[$name] = true;
        }
    }

    public function allows(DiagnosticEntry $entry): bool
    {
        return !isset($this->disabledCategories[$entry->category()])
            && !isset($this->disabledNames[$entry->name()]);
    }
}
