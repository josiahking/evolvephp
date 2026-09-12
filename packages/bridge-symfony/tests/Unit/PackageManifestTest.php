<?php

declare(strict_types=1);

namespace Evolve\Bridge\Symfony\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function test_manifest_declares_exact_symfony_bridge_package_contract(): void
    {
        $manifest = $this->manifest();

        self::assertSame('evolvephp/bridge-symfony', $manifest['name']);
        self::assertSame('Symfony host Bridge adapter for embedded EvolvePHP 2 delegation.', $manifest['description']);
        self::assertSame('library', $manifest['type']);
        self::assertSame('BSD-3-Clause', $manifest['license']);
        self::assertSame(['Evolve\\Bridge\\Symfony\\' => 'src/'], $manifest['autoload']['psr-4']);
        self::assertSame(
            [
                'php' => '^8.4',
                'evolvephp/bridge-contracts' => '^2.0',
                'evolvephp/bridge-psr' => '^2.0',
                'psr/http-factory' => '^1.0',
                'psr/http-message' => '^1.1 || ^2.0',
                'symfony/http-foundation' => '^8.1',
                'symfony/security-core' => '^8.1',
            ],
            $manifest['require'],
        );

        foreach ([
            'symfony/framework-bundle',
            'symfony/psr-http-message-bridge',
            'guzzlehttp/psr7',
            'laminas/laminas-diactoros',
            'nyholm/psr7',
            'slim/psr7',
            'evolvephp/core',
            'evolvephp/http',
            'evolvephp/bridge-remote',
            'evolvephp/dev-tools',
        ] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $manifest['require']);
            self::assertArrayNotHasKey($forbidden, $manifest['require-dev'] ?? []);
        }

        foreach (['version', 'repositories', 'minimum-stability', 'prefer-stable'] as $forbiddenField) {
            self::assertArrayNotHasKey($forbiddenField, $manifest);
        }
    }

    public function test_public_source_inventory_is_exact(): void
    {
        self::assertSame(
            [
                'SymfonyBridgeAdapter.php',
                'SymfonyBridgeContextFactory.php',
                'SymfonyBridgeResult.php',
            ],
            $this->phpFilesUnder(dirname(__DIR__, 2) . '/src'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/composer.json');
        self::assertIsString($content);

        return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (new \DirectoryIterator($directory) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getFilename();
            }
        }

        sort($files);

        return $files;
    }
}
