<?php

declare(strict_types=1);

namespace Evolve\Observe\Logging;

final readonly class LogCorrelation
{
    public function __construct(
        private ?string $traceId = null,
        private ?string $spanId = null,
        private ?string $traceFlags = null,
        private ?string $executionId = null,
        private ?string $executionKind = null,
    ) {}

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    public function spanId(): ?string
    {
        return $this->spanId;
    }

    public function traceFlags(): ?string
    {
        return $this->traceFlags;
    }

    public function executionId(): ?string
    {
        return $this->executionId;
    }

    public function executionKind(): ?string
    {
        return $this->executionKind;
    }

    public function isEmpty(): bool
    {
        return $this->traceId === null
            && $this->spanId === null
            && $this->traceFlags === null
            && $this->executionId === null
            && $this->executionKind === null;
    }

    /**
     * @return array<string, string>
     */
    public function structuredFields(): array
    {
        $fields = [];

        if ($this->traceId !== null) {
            $fields['trace_id'] = $this->traceId;
        }

        if ($this->spanId !== null) {
            $fields['span_id'] = $this->spanId;
        }

        if ($this->traceFlags !== null) {
            $fields['trace_flags'] = $this->traceFlags;
        }

        return $fields + $this->openTelemetryAttributes();
    }

    /**
     * @return array<string, string>
     */
    public function openTelemetryAttributes(): array
    {
        $attributes = [];

        if ($this->executionId !== null) {
            $attributes['evolve.execution.id'] = $this->executionId;
        }

        if ($this->executionKind !== null) {
            $attributes['evolve.execution.kind'] = $this->executionKind;
        }

        return $attributes;
    }
}
