<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Unit\Observability;

use Evolve\Mcp\Observability\McpOperation;
use Evolve\Mcp\Observability\McpOperationDispatcher;
use Evolve\Mcp\Observability\McpOperationSink;
use PHPUnit\Framework\TestCase;

final class McpOperationDispatcherTest extends TestCase
{
    public function test_disabled_dispatch_is_noop_and_throwing_sink_is_contained(): void
    {
        $event = new McpOperation('invoke', 'http', 'denied');
        (new McpOperationDispatcher())->dispatch($event);
        $calls = 0;
        $sink = new class ($calls) implements McpOperationSink {
            public function __construct(private int &$calls) {}
            public function record(array $fields): void
            {
                ++$this->calls;
                throw new \RuntimeException('sink detail');
            }
        };
        (new McpOperationDispatcher($sink))->dispatch($event);
        self::assertSame(1, $calls);
    }
}
