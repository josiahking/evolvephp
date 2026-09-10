<?php

declare(strict_types=1);

namespace Evolve\Bridge\Contracts\Tests\Unit;

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

        self::assertSame('evolvephp/bridge-contracts', $manifest['name']);
        self::assertSame(
            ['Evolve\\Bridge\\Contracts\\' => 'src/'],
            $manifest['autoload']['psr-4'],
        );
        self::assertSame('^8.4', $manifest['require']['php']);
        self::assertSame('^2.0', $manifest['require']['evolvephp/contracts']);

        foreach ([
            'evolvephp/core',
            'evolvephp/http',
            'illuminate/contracts',
            'laravel/framework',
            'symfony/http-foundation',
            'symfony/http-kernel',
            'psr/http-message',
            'psr/http-server-handler',
            'psr/http-server-middleware',
        ] as $forbiddenDependency) {
            self::assertArrayNotHasKey($forbiddenDependency, $manifest['require']);
        }

        self::assertArrayNotHasKey('require-dev', $manifest);
    }
}
