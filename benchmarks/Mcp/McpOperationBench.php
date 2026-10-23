<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Mcp;

use Evolve\Mcp\Observability\McpOperation;
use Evolve\Mcp\Observability\McpOperationDispatcher;
use Evolve\Mcp\Observability\McpOperationSink;

/** Repeated execution plus optional production-shaped event construction and dispatch. No performance budget is inferred. */
final class McpOperationBench
{
    /** @return array{iterations: int, work_units: int, events_constructed: int, recorded: int, max_fields: int, elapsed_ns: int, memory_delta_bytes: int, php: string, os: string} */
    public function measure(int $iterations, bool $enabled): array
    {
        if ($iterations < 1 || $iterations > 1_000_000) {
            throw new \InvalidArgumentException('Benchmark iterations must be bounded.');
        }
        $recorded = 0;
        $workUnits = 0;
        $eventsConstructed = 0;
        $maxFields = 0;
        $sink = new class ($recorded, $maxFields) implements McpOperationSink {
            public function __construct(private int &$recorded, private int &$maxFields) {}
            public function record(array $fields): void
            {
                ++$this->recorded;
                $this->maxFields = max($this->maxFields, count($fields));
            }
        };
        $dispatcher = new McpOperationDispatcher($enabled ? $sink : null);
        $operation = static fn(int $value): int => $value + 1;
        $before = memory_get_usage(true);
        $started = hrtime(true);
        for ($i = 0; $i < $iterations; ++$i) {
            $workUnits = $operation($workUnits);
            if ($dispatcher->isEnabled()) {
                ++$eventsConstructed;
                $dispatcher->dispatch(new McpOperation('invoke', 'http', 'success', 'fixture.tool', durationNs: 1000));
            }
        }
        $elapsed = max(0, hrtime(true) - $started);
        return [
            'iterations' => $iterations,
            'work_units' => $workUnits,
            'events_constructed' => $eventsConstructed,
            'recorded' => $recorded,
            'max_fields' => $maxFields,
            'elapsed_ns' => $elapsed,
            'memory_delta_bytes' => memory_get_usage(true) - $before,
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
        ];
    }
}
