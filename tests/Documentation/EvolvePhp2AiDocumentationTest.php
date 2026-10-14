<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EvolvePhp2AiDocumentationTest extends TestCase
{
    public function testPackageReadmeDocumentsImplementedBoundaryAndLimits(): void
    {
        $root = dirname(__DIR__, 2);
        $readme = (string) file_get_contents($root . '/packages/ai/README.md');
        foreach ([
            'experimental pre-release',
            'PHP `^8.4`',
            'implements ModelProvider',
            'ToolCall',
            'ToolResultPart',
            'no direct runtime dependency',
            'no SDK or network call',
            'cooperative',
            'transport deadlines',
            'authorization',
            'ModelFailureCategory',
        ] as $expected) {
            self::assertStringContainsString($expected, $readme);
        }
        self::assertStringContainsString('evolvephp/ai', (string) file_get_contents($root . '/packages/README.md'));
        self::assertStringContainsString('composer test:ai', (string) file_get_contents($root . '/DEVELOPMENT.md'));
    }
}
