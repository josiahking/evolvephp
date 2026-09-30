<?php

declare(strict_types=1);

namespace Evolve\Storage\Local\Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function test_package_identity_namespace_and_exact_production_dependency_are_declared(): void
    {
        $manifestPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'composer.json';
        $contents = file_get_contents($manifestPath);

        self::assertNotFalse($contents);

        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $require = $manifest['require'];
        ksort($require);

        self::assertSame('evolvephp/storage-local', $manifest['name']);
        self::assertSame('library', $manifest['type']);
        self::assertSame('BSD-3-Clause', $manifest['license']);
        self::assertSame('https://github.com/josiahking/evolvephp', $manifest['homepage']);
        self::assertNotEmpty($manifest['authors']);
        self::assertNotEmpty($manifest['keywords']);
        self::assertSame([
            'evolvephp/storage-contracts' => '^2.0',
            'php' => '^8.4',
        ], $require);
        self::assertSame(
            ['Evolve\\Storage\\Local\\' => 'src/'],
            $manifest['autoload']['psr-4'],
        );
        self::assertSame(
            ['Evolve\\Storage\\Local\\Tests\\' => 'tests/'],
            $manifest['autoload-dev']['psr-4'],
        );
        self::assertArrayNotHasKey('require-dev', $manifest);

        foreach (array_keys($require) as $dependency) {
            self::assertNotContains($dependency, [
                'evolvephp/contracts',
                'evolvephp/core',
                'evolvephp/insight',
                'evolvephp/observe',
                'league/flysystem',
            ]);
        }
    }
}
