<?php

declare(strict_types=1);

namespace Evolve\Insight\Query;

use Evolve\Insight\Access\DiagnosticAccessDenied;
use Evolve\Insight\Access\DiagnosticAccessOperation;
use Evolve\Insight\Access\DiagnosticAccessPolicy;
use Evolve\Insight\Storage\DiagnosticBatchSnapshot;

final readonly class DiagnosticQueryService
{
    public function __construct(
        private DiagnosticBatchReader $reader,
        private DiagnosticAccessPolicy $accessPolicy,
    ) {}

    public function query(DiagnosticBatchQuery $query): DiagnosticBatchPage
    {
        if (!$this->accessPolicy->allows(DiagnosticAccessOperation::List)) {
            throw DiagnosticAccessDenied::list();
        }

        return $this->reader->query($query);
    }

    public function find(string $executionIdentifier): ?DiagnosticBatchSnapshot
    {
        if (!$this->accessPolicy->allows(DiagnosticAccessOperation::Detail, $executionIdentifier)) {
            throw DiagnosticAccessDenied::detail();
        }

        return $this->reader->find($executionIdentifier);
    }
}
