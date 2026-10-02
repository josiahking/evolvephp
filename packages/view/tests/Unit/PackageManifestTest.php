<?php

declare(strict_types=1);

namespace Evolve\View\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function test_package_has_only_php_runtime_requirement(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('evolvephp/view', $manifest['name']);
        self::assertSame(['php' => '^8.4'], $manifest['require']);
        self::assertSame(['Evolve\\View\\' => 'src/'], $manifest['autoload']['psr-4']);
    }
}
