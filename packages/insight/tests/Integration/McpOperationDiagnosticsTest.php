<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Integration;

use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\Capture\DiagnosticEntrySink;
use Evolve\Insight\Mcp\McpOperationDiagnostics;
use PHPUnit\Framework\TestCase;

final class McpOperationDiagnosticsTest extends TestCase
{
    public function test_records_bounded_local_summary_without_identity(): void
    {
        $sink = new class implements DiagnosticEntrySink {
            /** @var list<DiagnosticEntry> */
            public array $entries = [];
            public function capture(DiagnosticEntry $candidate): void
            {
                $this->entries[] = $candidate;
            }
        };
        $adapter = new McpOperationDiagnostics($sink, 'execution-1');
        $adapter->record(['kind' => 'invoke', 'transport' => 'http', 'outcome' => 'denied', 'capability_hash' => str_repeat('a', 64), 'duration_ns' => 12, 'failure_category' => 'policy']);
        self::assertCount(1, $sink->entries);
        self::assertSame('evolve.mcp', $sink->entries[0]->category());
        self::assertStringNotContainsString(str_repeat('a', 64), serialize($sink->entries[0]));
    }
    public function test_rejects_unbounded_correlation_even_though_it_is_not_persisted(): void
    {
        $sink = new class implements DiagnosticEntrySink {
            public function capture(DiagnosticEntry $candidate): void {}
        };
        $adapter = new McpOperationDiagnostics($sink, 'execution-1');
        $this->expectException(\InvalidArgumentException::class);
        $adapter->record(['kind' => 'invoke', 'transport' => 'http', 'outcome' => 'success', 'correlation' => str_repeat('a', 100)]);
    }
}
