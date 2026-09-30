<?php

declare(strict_types=1);

namespace Evolve\Storage\Contracts\Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function test_package_identity_namespace_runtime_and_dependencies_are_declared(): void
    {
        $manifestPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'composer.json';
        $contents = file_get_contents($manifestPath);

        self::assertNotFalse($contents);

        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('evolvephp/storage-contracts', $manifest['name']);
        self::assertSame('library', $manifest['type']);
        self::assertSame('BSD-3-Clause', $manifest['license']);
        self::assertSame('^8.4', $manifest['require']['php']);
        self::assertSame('^2.0', $manifest['require']['evolvephp/contracts']);

        $require = $manifest['require'];
        ksort($require);
        self::assertSame([
            'evolvephp/contracts' => '^2.0',
            'php' => '^8.4',
        ], $require);

        self::assertSame(
            ['Evolve\\Storage\\Contracts\\' => 'src/'],
            $manifest['autoload']['psr-4'],
        );
        self::assertSame(
            ['Evolve\\Storage\\Contracts\\Tests\\' => 'tests/'],
            $manifest['autoload-dev']['psr-4'],
        );
        self::assertArrayNotHasKey('require-dev', $manifest);
        self::assertSame('https://github.com/josiahking/evolvephp', $manifest['homepage']);
        self::assertNotEmpty($manifest['authors']);
        self::assertNotEmpty($manifest['keywords']);
    }
}
