<?php

declare(strict_types=1);

namespace Evolve\Ai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function test_optional_package_requires_only_php(): void
    {
        $package = dirname(__DIR__, 2);
        $manifest = json_decode((string) file_get_contents($package . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('evolvephp/ai', $manifest['name']);
        self::assertSame(['php' => '^8.4'], $manifest['require']);
        self::assertSame(['Evolve\\Ai\\' => 'src/'], $manifest['autoload']['psr-4']);
        self::assertSame('BSD-3-Clause', $manifest['license']);
        self::assertSame(file_get_contents(dirname(__DIR__, 4) . '/LICENSE.md'), file_get_contents($package . '/LICENSE.md'));
    }
}
