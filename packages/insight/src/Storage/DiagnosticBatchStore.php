<?php

declare(strict_types=1);

namespace Evolve\Insight\Storage;

interface DiagnosticBatchStore
{
    public function save(DiagnosticBatchSnapshot $snapshot): void;

    public function find(string $executionIdentifier): ?DiagnosticBatchSnapshot;

    /**
     * @return list<DiagnosticBatchSnapshot>
     */
    public function latest(int $limit): array;
}
