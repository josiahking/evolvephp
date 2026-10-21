<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Mcp/McpOperationBench.php';

final class McpOperationBenchmarkFixtureTest extends TestCase
{
    public function test_repeated_enabled_and_disabled_workloads_are_equivalent_and_bounded(): void
    {
        $bench = new \Evolve\Benchmarks\Mcp\McpOperationBench();
        $disabled = $bench->measure(1000, false);
        $enabled = $bench->measure(1000, true);
        self::assertSame(1000, $disabled['iterations']);
        self::assertSame(1000, $enabled['iterations']);
        self::assertSame(0, $disabled['recorded']);
        self::assertSame(1000, $enabled['recorded']);
        self::assertSame(1000, $disabled['work_units']);
        self::assertSame($disabled['work_units'], $enabled['work_units']);
        self::assertSame(0, $disabled['events_constructed']);
        self::assertSame(1000, $enabled['events_constructed']);
        self::assertLessThanOrEqual(7, $enabled['max_fields']);
        self::assertSame(PHP_VERSION, $enabled['php']);
    }
}
