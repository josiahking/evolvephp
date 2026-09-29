<?php

declare(strict_types=1);

namespace Evolve\Session\Contracts\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function testManifestDeclaresAcceptedSessionContractsPackageMetadata(): void
    {
        $manifest = $this->readJson('packages/session-contracts/composer.json');

        self::assertSame('evolvephp/session-contracts', $manifest['name']);
        self::assertSame('Runtime-neutral session contracts for EvolvePHP 2.', $manifest['description']);
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
            ['Evolve\\Session\\Contracts\\' => 'src/'],
            $manifest['autoload']['psr-4'],
        );
        self::assertSame(
            ['Evolve\\Session\\Contracts\\Tests\\' => 'tests/'],
            $manifest['autoload-dev']['psr-4'],
        );
        self::assertArrayNotHasKey('scripts', $manifest);
        self::assertArrayNotHasKey('provide', $manifest);

        foreach ([
            'ext-session',
            'evolvephp/core',
            'evolvephp/http',
            'evolvephp/cache-memory',
            'evolvephp/database-contracts',
            'evolvephp/database-pdo',
            'evolvephp/bridge-contracts',
            'evolvephp/insight',
            'evolvephp/observe',
            'psr/http-message',
            'psr/http-server-handler',
            'psr/http-server-middleware',
        ] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $manifest['require']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $relativePath): array
    {
        $path = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        self::assertFileExists($path);

        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data);

        return $data;
    }
}
