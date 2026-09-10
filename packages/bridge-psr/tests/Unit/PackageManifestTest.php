<?php

declare(strict_types=1);

namespace Evolve\Bridge\Psr\Tests\Unit;

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

        self::assertSame('evolvephp/bridge-psr', $manifest['name']);
        self::assertSame(
            ['Evolve\\Bridge\\Psr\\' => 'src/'],
            $manifest['autoload']['psr-4'],
        );
        self::assertSame('^8.4', $manifest['require']['php']);
        self::assertSame('^2.0', $manifest['require']['evolvephp/bridge-contracts']);
        self::assertSame('^2.0', $manifest['require']['evolvephp/core']);
        self::assertSame('^2.0', $manifest['require']['evolvephp/http']);
        self::assertSame('^1.1 || ^2.0', $manifest['require']['psr/http-message']);

        foreach ([
            'evolvephp/testing',
            'evolvephp/dev-tools',
            'illuminate/contracts',
            'laravel/framework',
            'symfony/http-foundation',
            'symfony/http-kernel',
            'psr/http-factory',
            'psr/http-server-handler',
            'psr/http-server-middleware',
            'guzzlehttp/psr7',
            'laminas/laminas-diactoros',
            'nyholm/psr7',
            'slim/psr7',
            'symfony/psr-http-message-bridge',
        ] as $forbiddenDependency) {
            self::assertArrayNotHasKey($forbiddenDependency, $manifest['require']);
        }

        self::assertArrayNotHasKey('require-dev', $manifest);
    }
}
