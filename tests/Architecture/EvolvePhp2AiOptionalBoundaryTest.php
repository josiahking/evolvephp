<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EvolvePhp2AiOptionalBoundaryTest extends TestCase
{
    public function testAiIsOptionalAndIndependentOfCoreAndMcp(): void
    {
        $root = dirname(__DIR__, 2);
        $rootManifest = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $aiManifest = json_decode((string) file_get_contents($root . '/packages/ai/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $mcpManifest = json_decode((string) file_get_contents($root . '/packages/mcp/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $coreManifest = json_decode((string) file_get_contents($root . '/packages/core/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('evolvephp/ai', $rootManifest['require']);
        self::assertSame('^2.0@dev', $rootManifest['require-dev']['evolvephp/ai']);
        self::assertSame(['php' => '^8.4'], $aiManifest['require']);
        self::assertArrayNotHasKey('evolvephp/ai', $mcpManifest['require']);
        self::assertArrayNotHasKey('evolvephp/ai', $coreManifest['require']);
        self::assertArrayNotHasKey('evolvephp/mcp', $aiManifest['require']);
    }
}
