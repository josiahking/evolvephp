<?php

declare(strict_types=1);

namespace Evolve\Cache\Memory\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function testManifestDeclaresCacheMemoryPackageMetadata(): void
    {
        $manifest = $this->readJson('packages/cache-memory/composer.json');

        self::assertSame('evolvephp/cache-memory', $manifest['name']);
        self::assertSame('In-memory PSR-16 cache implementation for EvolvePHP 2.', $manifest['description']);
        self::assertSame('library', $manifest['type']);
        self::assertSame('BSD-3-Clause', $manifest['license']);
        self::assertSame(
            [
                'php' => '^8.4',
                'psr/clock' => '^1.0',
                'psr/simple-cache' => '^3.0',
            ],
            $manifest['require'],
        );
        self::assertSame(
            ['psr/simple-cache-implementation' => '3.0'],
            $manifest['provide'],
        );
        self::assertSame(
            ['Evolve\\Cache\\Memory\\' => 'src/'],
            $manifest['autoload']['psr-4'],
        );
        self::assertSame(
            ['Evolve\\Cache\\Memory\\Tests\\' => 'tests/'],
            $manifest['autoload-dev']['psr-4'],
        );
        self::assertArrayNotHasKey('scripts', $manifest);

        foreach ([
            'evolvephp/contracts',
            'evolvephp/core',
            'evolvephp/insight',
            'evolvephp/observe',
            'ext-redis',
            'ext-apcu',
            'ext-memcached',
        ] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $manifest['require']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $relativePath): array
    {
        $path = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        self::assertFileExists($path);

        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data);

        return $data;
    }
}
