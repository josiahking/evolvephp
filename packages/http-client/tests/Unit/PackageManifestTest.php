<?php

declare(strict_types=1);

namespace Evolve\Http\Client\Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    /** @throws JsonException */
    public function test_package_manifest_is_narrow_and_vendor_neutral(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/composer.json');
        self::assertNotFalse($contents);
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('evolvephp/http-client', $manifest['name']);
        self::assertSame('PSR-18 outbound HTTP client composition foundation for EvolvePHP 2.', $manifest['description']);
        self::assertSame('library', $manifest['type']);
        self::assertSame('BSD-3-Clause', $manifest['license']);
        self::assertSame([
            'php' => '^8.4',
            'psr/http-client' => '^1.0',
            'psr/http-message' => '^1.1 || ^2.0',
        ], $manifest['require']);
        self::assertSame(['Evolve\\Http\\Client\\' => 'src/'], $manifest['autoload']['psr-4']);
        self::assertSame(['Evolve\\Http\\Client\\Tests\\' => 'tests/'], $manifest['autoload-dev']['psr-4']);
        self::assertArrayNotHasKey('require-dev', $manifest);
        self::assertArrayNotHasKey('psr/http-factory', $manifest['require']);

        foreach (array_keys($manifest['require']) as $dependency) {
            self::assertFalse(str_starts_with($dependency, 'evolvephp/'));
        }
    }
}
