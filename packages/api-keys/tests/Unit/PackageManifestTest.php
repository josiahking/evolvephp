<?php

declare(strict_types=1);

namespace Evolve\ApiKeys\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function testIndependentManifestDeclaresOwnedDependencies(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('evolvephp/api-keys', $manifest['name']);
        self::assertSame('^8.4', $manifest['require']['php']);
        self::assertSame('src/', $manifest['autoload']['psr-4']['Evolve\\ApiKeys\\']);
        foreach (['contracts', 'database-contracts', 'i18n', 'migration', 'module'] as $dependency) {
            self::assertArrayHasKey('evolvephp/' . $dependency, $manifest['require']);
        }
        self::assertArrayNotHasKey('evolvephp/core', $manifest['require']);
    }
}
