<?php

declare(strict_types=1);

namespace Evolve\Observe\Export;

final readonly class ExportLifecycleResult
{
    public function __construct(
        private ?bool $tracesSucceeded = null,
        private ?string $tracesErrorType = null,
        private ?bool $metricsSucceeded = null,
        private ?string $metricsErrorType = null,
        private ?bool $logsSucceeded = null,
        private ?string $logsErrorType = null,
    ) {}

    public function tracesSucceeded(): ?bool
    {
        return $this->tracesSucceeded;
    }

    public function tracesErrorType(): ?string
    {
        return $this->tracesErrorType;
    }

    public function metricsSucceeded(): ?bool
    {
        return $this->metricsSucceeded;
    }

    public function metricsErrorType(): ?string
    {
        return $this->metricsErrorType;
    }

    public function logsSucceeded(): ?bool
    {
        return $this->logsSucceeded;
    }

    public function logsErrorType(): ?string
    {
        return $this->logsErrorType;
    }

    public function hasFailures(): bool
    {
        return $this->tracesSucceeded === false
            || $this->metricsSucceeded === false
            || $this->logsSucceeded === false;
    }

    public function allSucceeded(): bool
    {
        $states = [$this->tracesSucceeded, $this->metricsSucceeded, $this->logsSucceeded];

        return in_array(true, $states, true) && !in_array(false, $states, true);
    }
}
