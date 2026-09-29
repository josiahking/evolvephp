<?php

declare(strict_types=1);

namespace Evolve\Lock\Contracts\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function test_manifest_declares_exact_lock_contract_package_policy(): void
    {
        $manifest = $this->readPackageJson('composer.json');

        self::assertSame('evolvephp/lock-contracts', $manifest['name']);
        self::assertSame('Runtime-neutral lock and lease contracts for EvolvePHP 2.', $manifest['description']);
        self::assertSame('library', $manifest['type']);
        self::assertSame('BSD-3-Clause', $manifest['license']);
        self::assertSame([
            'php' => '^8.4',
            'evolvephp/contracts' => '^2.0',
        ], $manifest['require']);
        self::assertSame(
            ['Evolve\\Lock\\Contracts\\' => 'src/'],
            $manifest['autoload']['psr-4'],
        );

        foreach ([
            'evolvephp/core',
            'evolvephp/session-contracts',
            'evolvephp/insight',
            'evolvephp/observe',
            'predis/predis',
            'ext-redis',
        ] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $manifest['require']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readPackageJson(string $path): array
    {
        $fullPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $path;

        self::assertFileExists($fullPath);

        $decoded = json_decode((string) file_get_contents($fullPath), true);

        self::assertSame(JSON_ERROR_NONE, json_last_error());
        self::assertIsArray($decoded);

        return $decoded;
    }
}
