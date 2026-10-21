<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Unit\Observability;

use Evolve\Mcp\Observability\McpOperation;
use PHPUnit\Framework\TestCase;

final class McpOperationTest extends TestCase
{
    public function test_only_safe_bounded_fields_leave_the_boundary(): void
    {
        $fields = (new McpOperation('invoke', 'http', 'denied', 'secret://private', str_repeat('x', 200), PHP_INT_MAX, 'policy'))->fields();
        self::assertSame(hash('sha256', 'secret://private'), $fields['capability_hash']);
        self::assertArrayNotHasKey('correlation', $fields);
        self::assertSame(60_000_000_000, $fields['duration_ns']);
        self::assertStringNotContainsString('private', json_encode($fields, JSON_THROW_ON_ERROR));
    }
}
