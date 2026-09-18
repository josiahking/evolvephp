<?php

declare(strict_types=1);

namespace Evolve\Insight\Query;

final class DiagnosticBatchPage
{
    private const int MAX_ITEM_COUNT = 100;

    /**
     * @var list<DiagnosticBatchSummary>
     */
    private array $items;

    /**
     * @param array<array-key, mixed> $items
     */
    public function __construct(array $items, private ?string $nextCursor)
    {
        if ($this->nextCursor === '') {
            throw new \InvalidArgumentException('Diagnostic batch page next cursor must not be empty.');
        }

        if (count($items) > self::MAX_ITEM_COUNT) {
            throw new \InvalidArgumentException('Diagnostic batch page must not contain more than 100 items.');
        }

        $validated = array();

        foreach ($items as $item) {
            if (!$item instanceof DiagnosticBatchSummary) {
                throw new \InvalidArgumentException('Diagnostic batch page items must be diagnostic batch summaries.');
            }

            $validated[] = $item;
        }

        $this->items = $validated;
    }

    /**
     * @return list<DiagnosticBatchSummary>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }
}
