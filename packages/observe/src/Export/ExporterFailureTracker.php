<?php

declare(strict_types=1);

namespace Evolve\Observe\Export;

use Throwable;

final class ExporterFailureTracker
{
    private int $traceFailureCount = 0;

    private ?string $traceLastErrorType = null;

    private int $metricFailureCount = 0;

    private ?string $metricLastErrorType = null;

    private int $logFailureCount = 0;

    private ?string $logLastErrorType = null;

    public function recordTraceFailure(?Throwable $throwable = null): void
    {
        ++$this->traceFailureCount;
        $this->traceLastErrorType = self::classify($throwable);
    }

    public function recordMetricFailure(?Throwable $throwable = null): void
    {
        ++$this->metricFailureCount;
        $this->metricLastErrorType = self::classify($throwable);
    }

    public function recordLogFailure(?Throwable $throwable = null): void
    {
        ++$this->logFailureCount;
        $this->logLastErrorType = self::classify($throwable);
    }

    public function traceFailureCount(): int
    {
        return $this->traceFailureCount;
    }

    public function traceLastErrorType(): ?string
    {
        return $this->traceLastErrorType;
    }

    public function metricFailureCount(): int
    {
        return $this->metricFailureCount;
    }

    public function metricLastErrorType(): ?string
    {
        return $this->metricLastErrorType;
    }

    public function logFailureCount(): int
    {
        return $this->logFailureCount;
    }

    public function logLastErrorType(): ?string
    {
        return $this->logLastErrorType;
    }

    private static function classify(?Throwable $throwable): string
    {
        return $throwable === null ? '_OTHER' : $throwable::class;
    }
}
