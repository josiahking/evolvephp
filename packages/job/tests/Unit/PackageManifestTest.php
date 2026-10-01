<?php

declare(strict_types=1);

namespace Evolve\Job\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function test_manifest_has_only_the_accepted_runtime_dependencies(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('evolvephp/job', $manifest['name']);
        self::assertSame('library', $manifest['type']);
        self::assertSame('BSD-3-Clause', $manifest['license']);
        self::assertSame(['php' => '^8.4', 'evolvephp/core' => '^2.0', 'evolvephp/queue-contracts' => '^2.0'], $manifest['require']);
        self::assertSame(['Evolve\\Job\\' => 'src/'], $manifest['autoload']['psr-4']);
        self::assertSame(['Evolve\\Job\\Tests\\' => 'tests/'], $manifest['autoload-dev']['psr-4']);
        self::assertArrayNotHasKey('require-dev', $manifest);
    }
}
