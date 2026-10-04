<?php

declare(strict_types=1);

namespace Evolve\I18n\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function test_package_is_core_only_with_optional_intl_and_canonical_release_order(): void
    {
        $package = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $release = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/release-packages.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('evolvephp/i18n', $package['name']);
        self::assertSame(['php' => '^8.4', 'evolvephp/core' => '^2.0'], $package['require']);
        self::assertArrayHasKey('ext-intl', $package['suggest']);
        self::assertSame(['Evolve\\I18n\\' => 'src/'], $package['autoload']['psr-4']);
        $names = array_column($release['packages'], 'name');
        self::assertSame(['evolvephp/view-blade', 'evolvephp/i18n', 'evolvephp/insight'], array_slice($names, array_search('evolvephp/view-blade', $names, true), 3));
    }
}
