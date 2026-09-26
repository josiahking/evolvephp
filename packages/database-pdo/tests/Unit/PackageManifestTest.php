<?php

declare(strict_types=1);

namespace Evolve\Database\Pdo\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function testManifestDeclaresPdoAdapterPackageMetadata(): void
    {
        $manifest = $this->readJson('packages/database-pdo/composer.json');

        $this->assertSame('evolvephp/database-pdo', $manifest['name']);
        $this->assertSame('PDO database adapter for EvolvePHP 2 database contracts.', $manifest['description']);
        $this->assertSame('library', $manifest['type']);
        $this->assertSame('BSD-3-Clause', $manifest['license']);
        $this->assertSame(
            [
                'php' => '^8.4',
                'ext-pdo' => '*',
                'evolvephp/contracts' => '^2.0',
                'evolvephp/database-contracts' => '^2.0',
            ],
            $manifest['require'],
        );
        $this->assertSame(
            ['Evolve\\Database\\Pdo\\' => 'src/'],
            $manifest['autoload']['psr-4'],
        );
        $this->assertSame(
            ['Evolve\\Database\\Pdo\\Tests\\' => 'tests/'],
            $manifest['autoload-dev']['psr-4'],
        );
        $this->assertArrayNotHasKey('scripts', $manifest);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $relativePath): array
    {
        $path = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertFileExists($path);

        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data);

        return $data;
    }
}
