<?php

declare(strict_types=1);

namespace Evolve\Insight\Storage;

final class InMemoryDiagnosticBatchStore implements DiagnosticBatchStore
{
    /**
     * @var array<string, DiagnosticBatchSnapshot>
     */
    private array $snapshotsByIdentifier = array();

    /**
     * @var list<string>
     */
    private array $insertionOrder = array();

    public function save(DiagnosticBatchSnapshot $snapshot): void
    {
        $identifier = $snapshot->executionIdentifier();

        if (isset($this->snapshotsByIdentifier[$identifier])) {
            throw new \LogicException('Diagnostic batch snapshot already exists for execution identifier.');
        }

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
}
