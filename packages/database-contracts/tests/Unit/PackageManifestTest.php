<?php

declare(strict_types=1);

namespace Evolve\Database\Contracts\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function test_manifest_declares_exact_database_contract_package_policy(): void
    {
        $manifest = $this->readPackageJson('composer.json');

        self::assertSame('evolvephp/database-contracts', $manifest['name']);
        self::assertSame('Portable SQL database statement and transaction contracts for EvolvePHP 2.', $manifest['description']);
        self::assertSame('library', $manifest['type']);
        self::assertSame('BSD-3-Clause', $manifest['license']);
        self::assertSame(
            [
                'php' => '^8.4',
                'evolvephp/contracts' => '^2.0',
            ],
            $manifest['require'],
        );
        self::assertSame(
            ['Evolve\\Database\\Contracts\\' => 'src/'],
            $manifest['autoload']['psr-4'],
        );

        foreach ([
            'evolvephp/core',
            'ext-pdo',
            'evolvephp/insight',
            'evolvephp/observe',
            'doctrine/dbal',
            'illuminate/database',
            'cycle/database',
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
