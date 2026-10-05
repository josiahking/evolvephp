<?php

declare(strict_types=1);

namespace Evolve\Insight\Infrastructure;

final readonly class DatabaseDiagnosticPolicy
{
    public function __construct(
        public ?int $slowThresholdNanoseconds = null,
        public bool $captureSql = false,
        public int $maximumSqlLength = 512,
        public int $repeatThreshold = 2,
    ) {
        if ($slowThresholdNanoseconds !== null && $slowThresholdNanoseconds <= 0) {
            throw new \InvalidArgumentException('Slow threshold must be positive.');
        }
        if ($maximumSqlLength < 1 || $maximumSqlLength > 2048) {
            throw new \InvalidArgumentException('Maximum SQL length must be between 1 and 2048.');
        }
        if ($repeatThreshold < 1 || $repeatThreshold > 100) {
            throw new \InvalidArgumentException('Repeat threshold must be between 1 and 100.');
        }
    }
}
