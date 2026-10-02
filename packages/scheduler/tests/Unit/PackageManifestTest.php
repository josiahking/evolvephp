<?php

declare(strict_types=1);

namespace Evolve\Scheduler\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function test_manifest_and_release_inventory(): void
    {
        $package = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('evolvephp/scheduler', $package['name']);
        self::assertSame([
            'php' => '^8.4',
            'evolvephp/core' => '^2.0',
            'evolvephp/lock-contracts' => '^2.0',
            'evolvephp/queue-contracts' => '^2.0',
            'psr/clock' => '^1.0',
            'dragonmantank/cron-expression' => '^3.6',
        ], $package['require']);
        self::assertSame(['Evolve\\Scheduler\\' => 'src/'], $package['autoload']['psr-4']);
        self::assertSame(['Evolve\\Scheduler\\Tests\\' => 'tests/'], $package['autoload-dev']['psr-4']);
        self::assertArrayNotHasKey('require-dev', $package);
        $inventory = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/release-packages.json'), true, flags: JSON_THROW_ON_ERROR);
        $names = array_column($inventory['packages'], 'name');
        self::assertCount(27, $names);
        self::assertSame(['evolvephp/job', 'evolvephp/scheduler', 'evolvephp/insight'], array_slice($names, array_search('evolvephp/job', $names, true), 3));
    }
}
