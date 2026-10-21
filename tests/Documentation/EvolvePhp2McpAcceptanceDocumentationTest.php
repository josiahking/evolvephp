<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EvolvePhp2McpAcceptanceDocumentationTest extends TestCase
{
    public function test_current_manual_explains_opt_in_safety_and_transport_limits(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['packages/mcp/README.md', 'website/docs/next/packages/mcp.md'] as $path) {
            $content = (string) file_get_contents($root . '/' . $path);
            self::assertStringContainsString('McpOperationDispatcher', $content);
            self::assertStringContainsString('McpSecurityAuditSink', $content);
            self::assertStringContainsString('disabled by default', $content);
            self::assertStringContainsString('Linux PHP', $content);
        }
        self::assertStringContainsString('McpOperationInstrumentation', (string) file_get_contents($root . '/website/docs/next/observability/observe.md'));
        self::assertStringContainsString('McpOperationDiagnostics', (string) file_get_contents($root . '/website/docs/next/observability/insight.md'));
    }
}
