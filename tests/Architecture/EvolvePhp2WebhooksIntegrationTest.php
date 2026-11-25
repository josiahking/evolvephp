<?php
declare(strict_types=1);
namespace Evolve\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class EvolvePhp2WebhooksIntegrationTest extends TestCase
{
    public function testCurrentManualDocumentsTransportAndReplayBoundaries(): void
    {
        $root = dirname(__DIR__, 2);
        $manual = (string) file_get_contents($root . '/website/docs/next/packages/webhooks.md');
        self::assertStringContainsString('TrustedTransport', $manual);
        self::assertStringContainsString('ReplayStore', $manual);
        self::assertStringContainsString('3 seconds', $manual);
        self::assertStringContainsString('10 seconds', $manual);
        self::assertStringContainsString('not yet independently published', $manual);
    }
    public function testPackageIsInCurrentSourceInventory(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $release = json_decode((string) file_get_contents($root . '/release-packages.json'), true, 512, JSON_THROW_ON_ERROR);
        $destinations = json_decode((string) file_get_contents($root . '/tools/release-package-destinations.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2.0.x-dev', $composer['repositories'][0]['options']['versions']['evolvephp/webhooks']);
        self::assertSame('^2.0@dev', $composer['require-dev']['evolvephp/webhooks']);
        self::assertCount(37, $release['packages']);
        self::assertContains(['name' => 'evolvephp/webhooks', 'directory' => 'packages/webhooks'], $release['packages']);
        self::assertCount(37, $destinations['packages']);
        self::assertStringContainsString('name="webhooks"', (string) file_get_contents($root . '/phpunit.xml.dist'));
        self::assertStringContainsString('count($p["packages"]) === 37', (string) file_get_contents($root . '/.github/workflows/quality.yml'));
    }
}