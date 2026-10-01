<?php

declare(strict_types=1);

namespace Evolve\Secret\Contracts\Tests\Unit;

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

        self::assertSame('evolvephp/secret-contracts', $manifest['name']);
        self::assertSame('Vendor-neutral secret resolution contracts for EvolvePHP 2.', $manifest['description']);
        self::assertSame('library', $manifest['type']);
        self::assertSame('https://github.com/josiahking/evolvephp', $manifest['homepage']);
        self::assertSame('BSD-3-Clause', $manifest['license']);
        self::assertNotEmpty($manifest['authors']);
        self::assertNotEmpty($manifest['keywords']);
        self::assertSame(['php' => '^8.4', 'evolvephp/contracts' => '^2.0'], $manifest['require']);
        self::assertSame(['Evolve\\Secret\\Contracts\\' => 'src/'], $manifest['autoload']['psr-4']);
        self::assertSame(['Evolve\\Secret\\Contracts\\Tests\\' => 'tests/'], $manifest['autoload-dev']['psr-4']);
        self::assertArrayNotHasKey('require-dev', $manifest);
    }

    public function test_production_source_does_not_use_ambient_environment_lookup(): void
    {
        $sourceDirectory = dirname(__DIR__, 2) . '/src';
        if (!is_dir($sourceDirectory)) {
            self::fail('The production source directory must exist.');
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDirectory));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertNotFalse($source);
            self::assertDoesNotMatchRegularExpression('/\bgetenv\s*\(|\$_ENV\b|\$_SERVER\b/', $source);
        }
    }
}
