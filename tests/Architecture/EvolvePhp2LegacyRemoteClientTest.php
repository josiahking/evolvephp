<?php

declare(strict_types=1);

namespace EvolvePHP\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class EvolvePhp2LegacyRemoteClientTest extends TestCase
{
    public function test_legacy_remote_client_is_isolated_compatibility_artifact(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = $this->json($root . '/compat/legacy-http-client/composer.json');

        self::assertDirectoryExists($root . '/compat/legacy-http-client');
        self::assertDirectoryDoesNotExist($root . '/packages/legacy-http-client');
        self::assertSame('evolvephp/legacy-http-client', $manifest['name']);
        self::assertSame('library', $manifest['type']);
        self::assertSame('BSD-3-Clause', $manifest['license']);
        self::assertSame(['Evolve\\Bridge\\LegacyHttp\\' => 'src/'], $manifest['autoload']['psr-4']);
        self::assertSame(['php' => '^7.4 || ^8.0', 'ext-json' => '*', 'ext-curl' => '*'], $manifest['require']);
        self::assertArrayNotHasKey('repositories', $manifest);
        self::assertArrayNotHasKey('minimum-stability', $manifest);
        self::assertArrayNotHasKey('version', $manifest);
        self::assertArrayNotHasKey('config', $manifest);

        foreach (array_keys($manifest['require']) as $dependency) {
            self::assertFalse(strpos($dependency, 'evolvephp/') === 0);
            self::assertFalse(strpos($dependency, 'psr/') === 0);
        }
    }

    public function test_normal_release_inventory_and_root_composer_remain_uncontaminated(): void
    {
        $root = dirname(__DIR__, 2);
        $releaseMap = $this->json($root . '/release-packages.json');
        $rootComposer = $this->json($root . '/composer.json');

        self::assertCount(12, $releaseMap['packages']);
        self::assertNotContains('evolvephp/legacy-http-client', array_column($releaseMap['packages'], 'name'));
        self::assertArrayNotHasKey('evolvephp/legacy-http-client', $rootComposer['require']);
        self::assertArrayNotHasKey('evolvephp/legacy-http-client', $rootComposer['require-dev']);

        foreach ($releaseMap['packages'] as $package) {
            $manifest = $this->json($root . '/' . $package['directory'] . '/composer.json');
            self::assertSame('^8.4', $manifest['require']['php']);
        }
    }

    public function test_legacy_source_does_not_depend_on_evolvephp2_implementation_classes(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $this->source($root . '/compat/legacy-http-client/src');

        self::assertStringContainsString('namespace Evolve\\Bridge\\LegacyHttp;', $source);
        self::assertDoesNotMatchRegularExpression('/use Evolve\\\\(Bridge\\\\(Remote|Contracts|Psr)|Core|Http|Contracts|Module|Plugin|Testing|DevTools)\\\\/', $source);
        self::assertDoesNotMatchRegularExpression('/\breadonly\b|\benum\s+\w|\bmatch\s*\(|\?\->/', $source);
        self::assertDoesNotMatchRegularExpression('/function\s+[A-Za-z0-9_]+\s*\([^)]*,\s*\)/', $source);
        self::assertStringNotContainsString('array_is_list(', $source);
        self::assertStringNotContainsString('str_contains(', $source);
    }

    public function test_legacy_curl_transport_uses_bounded_response_capture_and_conservative_timeouts(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root . '/compat/legacy-http-client/src/LegacyCurlTransport.php');

        self::assertIsString($source);
        self::assertStringContainsString('CURLOPT_WRITEFUNCTION', $source);
        self::assertStringContainsString('LegacyRemoteProtocol::MAX_BODY_BYTES + 1', $source);
        self::assertStringContainsString('CURLOPT_RETURNTRANSFER => false', $source);
        self::assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $source);
        self::assertStringContainsString('CURLOPT_SSL_VERIFYPEER => true', $source);
        self::assertStringContainsString('CURLOPT_SSL_VERIFYHOST => 2', $source);
        self::assertStringContainsString('CURLE_OPERATION_TIMEDOUT', $source);
        self::assertStringContainsString('uncertainTransportFailure()', $source);
        self::assertStringNotContainsString('strlen($bodyResult)', $source);
    }

    public function test_ci_and_documentation_capture_php74_compatibility_boundary(): void
    {
        $root = dirname(__DIR__, 2);
        $workflow = file_get_contents($root . '/.github/workflows/quality.yml');
        $packagesReadme = file_get_contents($root . '/packages/README.md');
        $development = file_get_contents($root . '/DEVELOPMENT.md');

        self::assertIsString($workflow);
        self::assertStringContainsString('Legacy remote client (PHP 7.4)', $workflow);
        self::assertStringContainsString("LEGACY_REMOTE_CLIENT_PHP: '7.4'", $workflow);
        self::assertStringContainsString('compat/legacy-http-client/tests/run.php', $workflow);
        self::assertStringContainsString('compat/legacy-http-client/phpstan.neon.dist', $workflow);
        self::assertStringContainsString('PHP_VERSION_ID < 70400 || PHP_VERSION_ID >= 80000', $workflow);
        self::assertStringContainsString('extension_loaded("json")', $workflow);
        self::assertStringContainsString('extension_loaded("curl")', $workflow);

        self::assertIsString($packagesReadme);
        self::assertStringContainsString('EvolvePHP 2 packages continue to require PHP `^8.4`', $packagesReadme);
        self::assertStringContainsString('isolated remote compatibility artifact', $packagesReadme);
        self::assertStringContainsString('does not enable embedded or same-process EvolvePHP on PHP 7', $packagesReadme);

        self::assertIsString($development);
        self::assertStringContainsString('php compat/legacy-http-client/tests/run.php', $development);
        self::assertStringContainsString('php vendor/bin/phpstan analyse --configuration compat/legacy-http-client/phpstan.neon.dist', $development);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $path): array
    {
        $content = file_get_contents($path);
        self::assertIsString($content);

        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function source(string $directory): string
    {
        $source = '';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                self::assertIsString($content);
                $source .= $content . "\n";
            }
        }

        return $source;
    }
}
