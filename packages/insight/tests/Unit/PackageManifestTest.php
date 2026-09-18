<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function test_package_identity_and_source_namespace_are_declared(): void
    {
        $manifestPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'composer.json';
        $contents = file_get_contents($manifestPath);

        self::assertNotFalse($contents);

        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('evolvephp/insight', $manifest['name']);
        self::assertSame(
            'Local diagnostic capture, persistence, query and access-policy foundation for EvolvePHP 2.',
            $manifest['description'],
        );
        self::assertSame(
            ['Evolve\\Insight\\' => 'src/'],
            $manifest['autoload']['psr-4'],
        );
    }
}
