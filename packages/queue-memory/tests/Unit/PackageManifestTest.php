<?php

declare(strict_types=1);

namespace Evolve\Queue\Memory\Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function test_package_identity_namespace_runtime_and_dependency_boundary_are_declared(): void
    {
        $manifestPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'composer.json';
        $contents = file_get_contents($manifestPath);

        self::assertNotFalse($contents);

        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('evolvephp/queue-memory', $manifest['name']);
        self::assertSame(
            ['Evolve\\Queue\\Memory\\' => 'src/'],
            $manifest['autoload']['psr-4'],
        );
        self::assertSame([
            'php' => '^8.4',
            'evolvephp/queue-contracts' => '^2.0',
        ], $manifest['require']);
        self::assertArrayNotHasKey('require-dev', $manifest);
    }
}
