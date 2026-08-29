<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function testPackageManifestDeclaresExpectedIdentity(): void
    {
        $manifest = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('evolvephp/dev-tools', $manifest['name']);
        self::assertSame('Evolve\\DevTools\\', array_key_first($manifest['autoload']['psr-4']));
        self::assertSame('src/', $manifest['autoload']['psr-4']['Evolve\\DevTools\\']);
    }
}
