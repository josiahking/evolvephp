<?php

declare(strict_types=1);

namespace Evolve\Insight\Query;

final readonly class DiagnosticBatchQuery
{
    private const int MAX_PAGE_SIZE = 100;

    private const array EXECUTION_KINDS = array(
        'http-request',
        'queue-message',
        'scheduled-job',
        'cli-command',
        'worker-task',
    );

    public function __construct(
        private int $pageSize,
        private ?string $cursor = null,
        private ?string $executionKind = null,
        private ?string $diagnosticCategory = null,
        private ?string $diagnosticName = null,
    ) {
        if ($this->pageSize < 1 || $this->pageSize > self::MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException('Diagnostic query page size must be between 1 and 100.');
        }

        $this->validateOptionalString($this->cursor, 'Diagnostic query cursor');
        $this->validateOptionalString($this->executionKind, 'Diagnostic query execution kind');
        $this->validateOptionalString($this->diagnosticCategory, 'Diagnostic query diagnostic category');
        $this->validateOptionalString($this->diagnosticName, 'Diagnostic query diagnostic name');

        if ($this->executionKind !== null && !in_array($this->executionKind, self::EXECUTION_KINDS, true)) {
            throw new \InvalidArgumentException('Diagnostic query execution kind is not supported.');
        }
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    public function cursor(): ?string
    {
        return $this->cursor;
    }

    public function executionKind(): ?string
    {
        return $this->executionKind;
    }

    public function diagnosticCategory(): ?string
    {
        return $this->diagnosticCategory;
    }

    public function diagnosticName(): ?string
    {
        return $this->diagnosticName;
    }

    private function validateOptionalString(?string $value, string $field): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' must not be empty.');
        }
    }
}
