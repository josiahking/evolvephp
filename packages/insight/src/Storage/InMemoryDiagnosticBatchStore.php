<?php

declare(strict_types=1);

namespace Evolve\Insight\Storage;

use Evolve\Insight\Query\DiagnosticBatchPage;
use Evolve\Insight\Query\DiagnosticBatchQuery;
use Evolve\Insight\Query\DiagnosticBatchReader;
use Evolve\Insight\Query\DiagnosticBatchSummary;

final class InMemoryDiagnosticBatchStore implements DiagnosticBatchStore, DiagnosticBatchReader
{
    /**
     * @var array<string, DiagnosticBatchSnapshot>
     */
    private array $snapshotsByIdentifier = array();

    /**
     * @var list<string>
     */
    private array $insertionOrder = array();

    public function __construct(private int $maximumStoredBatchCount)
    {
        if ($this->maximumStoredBatchCount <= 0) {
            throw new \InvalidArgumentException('Maximum stored diagnostic batch count must be positive.');
        }
    }

    public function save(DiagnosticBatchSnapshot $snapshot): void
    {
        $identifier = $snapshot->executionIdentifier();

        if (isset($this->snapshotsByIdentifier[$identifier])) {
            throw new \LogicException('Diagnostic batch snapshot already exists for execution identifier.');
        }

        $this->pruneOldestSnapshotsForIncomingSave();

        $this->snapshotsByIdentifier[$identifier] = $snapshot;
        $this->insertionOrder[] = $identifier;
    }

    public function find(string $executionIdentifier): ?DiagnosticBatchSnapshot
    {
        return $this->snapshotsByIdentifier[$executionIdentifier] ?? null;
    }

    public function latest(int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Latest limit must be positive.');
        }

        $identifiers = array_slice(array_reverse($this->insertionOrder), 0, $limit);

        return array_map(
            fn (string $identifier): DiagnosticBatchSnapshot => $this->snapshotsByIdentifier[$identifier],
            $identifiers,
        );
    }

    public function query(DiagnosticBatchQuery $query): DiagnosticBatchPage
    {
        $newestFirstIdentifiers = array_reverse($this->insertionOrder);
        $startIndex = 0;

        if ($query->cursor() !== null) {
            $cursorIndex = array_search($query->cursor(), $newestFirstIdentifiers, true);

            if ($cursorIndex === false) {
                throw new \InvalidArgumentException('Diagnostic query cursor does not reference a retained batch.');
            }

            $startIndex = $cursorIndex + 1;
        }

        $items = array();
        $hasOlderMatch = false;

        for ($index = $startIndex, $count = count($newestFirstIdentifiers); $index < $count; $index++) {
            $snapshot = $this->snapshotsByIdentifier[$newestFirstIdentifiers[$index]];

            if (!$this->matchesQuery($snapshot, $query)) {
                continue;
            }

            if (count($items) < $query->pageSize()) {
                $items[] = DiagnosticBatchSummary::fromSnapshot($snapshot);

                continue;
            }

            $hasOlderMatch = true;
            break;
        }

        return new DiagnosticBatchPage(
            $items,
            $hasOlderMatch && $items !== array() ? $items[array_key_last($items)]->executionIdentifier() : null,
        );
    }

    private function pruneOldestSnapshotsForIncomingSave(): void
    {
        while (count($this->insertionOrder) >= $this->maximumStoredBatchCount) {
            $oldestIdentifier = array_shift($this->insertionOrder);

            if ($oldestIdentifier === null) {
                return;
            }

            unset($this->snapshotsByIdentifier[$oldestIdentifier]);
        }
    }

    private function matchesQuery(DiagnosticBatchSnapshot $snapshot, DiagnosticBatchQuery $query): bool
    {
        if ($query->executionKind() !== null && $snapshot->executionKind() !== $query->executionKind()) {
            return false;
        }

        if ($query->diagnosticCategory() === null && $query->diagnosticName() === null) {
            return true;
        }

        foreach ($snapshot->diagnosticEntries() as $entry) {
            if ($query->diagnosticCategory() !== null && $entry->category() !== $query->diagnosticCategory()) {
                continue;
            }

            if ($query->diagnosticName() !== null && $entry->name() !== $query->diagnosticName()) {
                continue;
            }

            return true;
        }

        return false;
    }
}
