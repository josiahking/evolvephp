<?php

declare(strict_types=1);

namespace Evolve\AuditLog\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function testManifestAndCanonicalReleaseOrder(): void
    {
        $root = dirname(__DIR__, 4);
        $manifest = json_decode((string) file_get_contents($root . '/packages/audit-log/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('evolvephp/audit-log', $manifest['name']);
        self::assertSame([
            'php' => '^8.4',
            'evolvephp/contracts' => '^2.0',
            'evolvephp/database-contracts' => '^2.0',
            'evolvephp/i18n' => '^2.0',
            'evolvephp/migration' => '^2.0',
            'evolvephp/module' => '^2.0',
            'psr/container' => '^1.1 || ^2.0',
        ], $manifest['require']);
        self::assertSame(['Evolve\\AuditLog\\' => 'src/'], $manifest['autoload']['psr-4']);
        $map = json_decode((string) file_get_contents($root . '/release-packages.json'), true, flags: JSON_THROW_ON_ERROR);
        $names = array_column($map['packages'], 'name');
        self::assertCount(37, $names);
        $position = array_search('evolvephp/module', $names, true);
        self::assertIsInt($position);
        self::assertSame('evolvephp/audit-log', $names[$position + 1]);
        self::assertSame('evolvephp/webhooks', $names[$position + 2]);
        self::assertSame('evolvephp/api-keys', $names[$position + 3]);
        self::assertCount(37, array_unique($names));
    }
}
