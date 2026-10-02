<?php

declare(strict_types=1);

namespace Evolve\Migration\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function test_exact_package_dependencies_and_release_order(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('evolvephp/migration', $manifest['name']);
        self::assertSame([
            'php' => '^8.4',
            'evolvephp/contracts' => '^2.0',
            'evolvephp/core' => '^2.0',
            'evolvephp/database-contracts' => '^2.0',
            'evolvephp/lock-contracts' => '^2.0',
        ], $manifest['require']);
        self::assertSame(['Evolve\\Migration\\' => 'src/'], $manifest['autoload']['psr-4']);
        $map = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/release-packages.json'), true, flags: JSON_THROW_ON_ERROR);
        $names = array_column($map['packages'], 'name');
        self::assertCount(28, $names);
        self::assertSame(['evolvephp/job', 'evolvephp/scheduler', 'evolvephp/migration', 'evolvephp/insight'], array_slice($names, array_search('evolvephp/job', $names, true), 4));
    }
}
