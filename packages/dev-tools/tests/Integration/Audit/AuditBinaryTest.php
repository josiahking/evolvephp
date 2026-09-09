<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Integration\Audit;

use PHPUnit\Framework\TestCase;

final class AuditBinaryTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = $this->createProjectRoot();
        $this->writeFixture($this->projectRoot);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testSourceBinaryAuditsNonEvolvePhpProjectInTextModeWithoutExecutingOrMutatingTarget(): void
    {
        $before = $this->targetInventory($this->projectRoot);
        $process = $this->runAudit([$this->projectRoot]);

        self::assertSame(0, $process['exit']);
        self::assertSame('', $process['stderr']);
        self::assertStringContainsString('Audit report', $process['stdout']);
        self::assertDoesNotMatchRegularExpression("/\n\n\\z/", $process['stdout']);
        self::assertDoesNotMatchRegularExpression("/\r\n\r\n\\z/", $process['stdout']);
        self::assertStringContainsString('[info] composer_json.present', $process['stdout']);
        self::assertStringContainsString('[warning] composer.frameworks', $process['stdout']);
        self::assertStringContainsString('[warning] composer_lock.composer_plugins', $process['stdout']);
        self::assertStringContainsString('[info] php_source.structure', $process['stdout']);
        self::assertStringContainsString('[warning] modernization.autoload_signals', $process['stdout']);
        self::assertStringContainsString('[warning] modernization.source_signals', $process['stdout']);
        self::assertStringContainsString('[risk] php_source.native_session_start', $process['stdout']);
        $this->assertTargetWasNotExecuted($this->projectRoot);
        self::assertSame($before, $this->targetInventory($this->projectRoot));
        self::assertStringNotContainsString($this->projectRoot, $process['stdout']);
        self::assertStringNotContainsString(str_replace('\\', '/', $this->projectRoot), $process['stdout']);
    }

    public function testJsonModeParsesWithSchemaVersionInspectorFindingsAndRiskExitZero(): void
    {
        $process = $this->runAudit([$this->projectRoot, '--format=json']);
        $decoded = json_decode($process['stdout'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $process['exit']);
        self::assertSame(1, $decoded['schema_version']);
        $identifiers = array_column($decoded['findings'], 'identifier');

        self::assertSame(1, count(array_keys($identifiers, 'composer_json.present', true)));
        self::assertSame(1, count(array_keys($identifiers, 'composer_lock.package_inventory', true)));
        self::assertSame(1, count(array_keys($identifiers, 'php_source.inventory', true)));
        self::assertSame(1, count(array_keys($identifiers, 'php_source.structure', true)));
        self::assertContains('modernization.autoload_signals', $identifiers);
        self::assertContains('modernization.source_signals', $identifiers);
        self::assertContains('php_source.native_session_start', $identifiers);
        self::assertLessThan(
            array_search('composer_lock.package_inventory', $identifiers, true),
            array_search('composer_json.present', $identifiers, true),
        );
        self::assertLessThan(
            array_search('php_source.inventory', $identifiers, true),
            array_search('composer_lock.package_inventory', $identifiers, true),
        );
        self::assertLessThan(
            array_search('php_source.structure', $identifiers, true),
            array_search('php_source.inventory', $identifiers, true),
        );
        self::assertStringNotContainsString($this->projectRoot, $process['stdout']);
        self::assertStringNotContainsString(str_replace('\\', '/', $this->projectRoot), $process['stdout']);
        $this->assertDecodedStringsDoNotContainTargetRoot($decoded, $this->projectRoot);
        $this->assertTargetWasNotExecuted($this->projectRoot);
    }

    public function testTargetDoesNotNeedEvolvePhpInstalledAndTrapsAreNotExecuted(): void
    {
        $process = $this->runAudit([$this->projectRoot, '--format=json']);

        self::assertSame(0, $process['exit']);
        self::assertDirectoryDoesNotExist($this->path($this->projectRoot, 'vendor/evolvephp'));
        $this->assertTargetWasNotExecuted($this->projectRoot);
    }

    public function testEquivalentFixtureContentsProduceDeterministicJson(): void
    {
        $secondRoot = $this->createProjectRoot();

        try {
            $this->writeFixture($secondRoot);

            self::assertSame(
                $this->runAudit([$this->projectRoot, '--format=json'])['stdout'],
                $this->runAudit([$secondRoot, '--format=json'])['stdout'],
            );
        } finally {
            $this->removeDirectory($secondRoot);
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runAudit(array $arguments): array
    {
        $command = array_merge([$this->phpBinary(), $this->repoPath('packages/dev-tools/bin/evolve-audit')], $arguments);
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, $this->repoPath('.'));

        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        self::assertIsString($stdout);
        self::assertIsString($stderr);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function writeFixture(string $root): void
    {
        self::assertTrue(mkdir($this->path($root, 'vendor'), 0777, true));
        self::assertTrue(mkdir($this->path($root, 'bootstrap'), 0777, true));
        self::assertTrue(mkdir($this->path($root, 'src'), 0777, true));

        file_put_contents($this->path($root, 'composer.json'), json_encode([
            'require' => [
                'php' => '^7.4 || ^8.0',
                'laravel/framework' => '^10.0',
            ],
            'autoload' => [
                'psr-4' => [
                    'Fixture\\Billing\\' => 'src/Billing/',
                ],
                'files' => ['bootstrap/autoload-trap.php'],
            ],
            'scripts' => [
                'post-install-cmd' => ['@php -r "file_put_contents(\'script-marker\', \'ran\');"'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($this->path($root, 'composer.lock'), json_encode([
            'packages' => [
                [
                    'name' => 'composer/plugin-trap',
                    'version' => '1.0.0',
                    'type' => 'composer-plugin',
                    'require' => ['php' => '^7.4'],
                ],
            ],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $evalPayload = 'file_put_contents(' . var_export($this->path($root, 'eval-marker'), true) . ", 'ran');";

        file_put_contents($this->path($root, 'vendor/autoload.php'), "<?php file_put_contents(__DIR__ . '/../autoload-marker', 'loaded');\n");
        file_put_contents($this->path($root, 'bootstrap/autoload-trap.php'), "<?php file_put_contents(__DIR__ . '/../autoload-files-marker', 'loaded');\n");
        file_put_contents($this->path($root, 'src/included.php'), '<?php file_put_contents(' . var_export($this->path($root, 'include-marker'), true) . ", 'loaded');\n");
        self::assertTrue(mkdir($this->path($root, 'src/Billing'), 0777, true));
        file_put_contents(
            $this->path($root, 'src/Trap.php'),
            "<?php\nnamespace Fixture\\Legacy;\nsession_start();\ninclude __DIR__ . '/included.php';\neval(" . var_export($evalPayload, true) . ");\n",
        );
        file_put_contents(
            $this->path($root, 'src/Billing/Invoice.php'),
            "<?php\nnamespace Fixture\\Billing;\nfinal class Invoice {}\n",
        );
    }

    private function assertTargetWasNotExecuted(string $root): void
    {
        self::assertFileDoesNotExist($this->path($root, 'autoload-marker'));
        self::assertFileDoesNotExist($this->path($root, 'autoload-files-marker'));
        self::assertFileDoesNotExist($this->path($root, 'script-marker'));
        self::assertFileDoesNotExist($this->path($root, 'include-marker'));
        self::assertFileDoesNotExist($this->path($root, 'eval-marker'));
    }

    /**
     * @return array<string, array{size: int, hash: string}>
     */
    private function targetInventory(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $files[$relativePath] = [
                    'size' => $file->getSize(),
                    'hash' => hash_file('sha256', $file->getPathname()),
                ];
            }
        }

        ksort($files);

        return $files;
    }

    private function phpBinary(): string
    {
        $php = getenv('PHP_BINARY');

        return is_string($php) && $php !== '' ? $php : PHP_BINARY;
    }

    /**
     * @param mixed $value
     */
    private function assertDecodedStringsDoNotContainTargetRoot(mixed $value, string $targetRoot): void
    {
        $normalizedRoot = str_replace('\\', '/', $targetRoot);

        if (is_string($value)) {
            self::assertStringNotContainsString($normalizedRoot, str_replace('\\', '/', $value));

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $nested) {
            $this->assertDecodedStringsDoNotContainTargetRoot($nested, $targetRoot);
        }
    }

    private function repoPath(string $relativePath): string
    {
        return dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function path(string $root, string $relativePath): string
    {
        return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function createProjectRoot(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evolvephp-audit-binary-test-' . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($path, 0777, true));

        return realpath($path) ?: $path;
    }

    private function removeDirectory(string $path): void
    {
        $real = realpath($path);

        if ($real === false) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entryPath = $entry->getPathname();

            if ($entry->isDir() && ! $entry->isLink()) {
                rmdir($entryPath);
                continue;
            }

            unlink($entryPath);
        }

        rmdir($real);
    }
}
