<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EvolvePhp2McpObservabilityBoundariesTest extends TestCase
{
    public function test_observe_and_insight_remain_independent_of_mcp(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['observe', 'insight'] as $package) {
            $manifest = json_decode((string) file_get_contents($root . '/packages/' . $package . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('evolvephp/mcp', $manifest['require']);
            $adapter = (string) file_get_contents($root . '/packages/' . $package . '/src/Mcp/' . ($package === 'observe' ? 'McpOperationInstrumentation.php' : 'McpOperationDiagnostics.php'));
            self::assertStringNotContainsString('use Evolve\\Mcp\\', $adapter);
        }
        $mcp = json_decode((string) file_get_contents($root . '/packages/mcp/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('evolvephp/observe', $mcp['require']);
        self::assertArrayNotHasKey('evolvephp/insight', $mcp['require']);
    }
}
