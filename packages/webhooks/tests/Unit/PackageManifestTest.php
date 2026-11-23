<?php

declare(strict_types=1);

namespace Evolve\Webhooks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function testIndependentPackageManifestHasOwnedDependencies(): void
    {
        $path = dirname(__DIR__, 2) . '/composer.json';
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('evolvephp/webhooks', $manifest['name']);
        self::assertSame('BSD-3-Clause', $manifest['license']);
        self::assertSame('^8.4', $manifest['require']['php']);
        self::assertSame('src/', $manifest['autoload']['psr-4']['Evolve\\Webhooks\\']);
        self::assertArrayHasKey('evolvephp/database-contracts', $manifest['require']);
        self::assertArrayHasKey('evolvephp/i18n', $manifest['require']);
        self::assertArrayHasKey('evolvephp/migration', $manifest['require']);
        self::assertArrayHasKey('evolvephp/module', $manifest['require']);
        self::assertArrayHasKey('evolvephp/secret-contracts', $manifest['require']);
    }
}
