<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Comparator;

use Closure;

final class PreparedScenario
{
    /**
     * @param Closure(): array<string, mixed> $subject
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $scenarioId,
        private readonly string $timingBoundary,
        private readonly int $preparedFrameworkInstanceCount,
        private readonly Closure $subject,
        private readonly array $metadata = [],
    ) {}

    public function scenarioId(): string
    {
        return $this->scenarioId;
    }

    public function timingBoundary(): string
    {
        return $this->timingBoundary;
    }

    public function preparedFrameworkInstanceCount(): int
    {
        return $this->preparedFrameworkInstanceCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'scenario_id' => $this->scenarioId,
            'timing_boundary' => $this->timingBoundary,
            'prepared_framework_instance_count' => $this->preparedFrameworkInstanceCount,
        ] + $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function runOnce(): array
    {
        $result = ($this->subject)();
        $result['scenario_id'] ??= $this->scenarioId;

        return $result;
    }

    /**
     * @return array{duration_microseconds: float, memory: array<string, int>, result: array<string, mixed>}
     */
    public function measureOnce(): array
    {
        $start = hrtime(true);
        $result = $this->runOnce();
        $duration = (hrtime(true) - $start) / 1000;

        return [
            'duration_microseconds' => $duration,
            'memory' => [
                'current_bytes' => memory_get_usage(true),
                'peak_bytes' => memory_get_peak_usage(true),
            ],
            'result' => $result,
        ];
    }
}
