<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    /** @throws JsonException */
    public function test_package_manifest_has_only_the_official_sdk_runtime_boundary(): void
    {
        $package = dirname(__DIR__, 2);
        $contents = file_get_contents($package . '/composer.json');
        self::assertNotFalse($contents);
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('evolvephp/mcp', $manifest['name']);
        self::assertSame('library', $manifest['type']);
        self::assertSame('BSD-3-Clause', $manifest['license']);
        self::assertSame(['php' => '^8.4', 'mcp/sdk' => '^0.8.1'], $manifest['require']);
        self::assertSame(['Evolve\\Mcp\\' => 'src/'], $manifest['autoload']['psr-4']);
        self::assertArrayNotHasKey('bin', $manifest);
        self::assertArrayNotHasKey('extra', $manifest);
        self::assertArrayNotHasKey('require-dev', $manifest);
        self::assertFileExists($package . '/README.md');
        self::assertSame(file_get_contents(dirname(__DIR__, 4) . '/LICENSE.md'), file_get_contents($package . '/LICENSE.md'));
        self::assertSame(['Server'], array_values(array_diff(scandir($package . '/src'), ['.', '..'])));
    }
}
