<?php

declare(strict_types=1);

namespace Evolve\Insight\Query;

use Evolve\Insight\Storage\DiagnosticBatchSnapshot;

interface DiagnosticBatchReader
{
    public function find(string $executionIdentifier): ?DiagnosticBatchSnapshot;

    public function query(DiagnosticBatchQuery $query): DiagnosticBatchPage;
}
