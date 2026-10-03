<?php

declare(strict_types=1);

namespace Evolve\View\Twig\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function test_runtime_dependencies_are_exact(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('evolvephp/view-twig', $manifest['name']);
        self::assertSame([
            'php' => '^8.4',
            'evolvephp/view' => '^2.0',
            'twig/twig' => '^3.30',
        ], $manifest['require']);
    }
}
