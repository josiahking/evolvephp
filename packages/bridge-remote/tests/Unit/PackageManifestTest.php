<?php

declare(strict_types=1);

namespace Evolve\Bridge\Remote\Tests\Unit;

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

        self::assertSame('evolvephp/bridge-remote', $manifest['name']);
        self::assertSame('Remote HTTP JSON Bridge protocol and PSR-15 server endpoint for EvolvePHP 2.', $manifest['description']);
        self::assertSame(['Evolve\\Bridge\\Remote\\' => 'src/'], $manifest['autoload']['psr-4']);
        self::assertSame('^8.4', $manifest['require']['php']);
        self::assertSame('^2.0', $manifest['require']['evolvephp/bridge-contracts']);
        self::assertSame('^2.0', $manifest['require']['evolvephp/bridge-psr']);
        self::assertSame('^1.0', $manifest['require']['psr/http-client']);
        self::assertSame('^1.0', $manifest['require']['psr/http-factory']);
        self::assertSame('^1.1 || ^2.0', $manifest['require']['psr/http-message']);
        self::assertSame('^1.0', $manifest['require']['psr/http-server-handler']);

        foreach ([
            'evolvephp/contracts',
            'evolvephp/core',
            'evolvephp/http',
            'evolvephp/testing',
            'evolvephp/dev-tools',
            'illuminate/contracts',
            'laravel/framework',
            'symfony/http-foundation',
            'symfony/http-kernel',
            'psr/http-server-middleware',
            'guzzlehttp/guzzle',
            'guzzlehttp/psr7',
            'php-http/httplug',
            'laminas/laminas-diactoros',
            'nyholm/psr7',
            'slim/psr7',
            'symfony/http-client',
            'symfony/http-client-contracts',
            'symfony/psr-http-message-bridge',
        ] as $forbiddenDependency) {
            self::assertArrayNotHasKey($forbiddenDependency, $manifest['require']);
        }

        self::assertArrayNotHasKey('require-dev', $manifest);
    }
}
