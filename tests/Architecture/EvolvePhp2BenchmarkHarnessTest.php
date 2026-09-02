<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2BenchmarkHarnessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testBenchmarkToolingIsIsolatedUnderBenchmarksComposerRoot(): void
    {
        $this->assertFileExists($this->projectPath('benchmarks/composer.json'));
        $this->assertFileExists($this->projectPath('benchmarks/phpbench.json'));
        $this->assertFileExists($this->projectPath('benchmarks/bin/benchmark-smoke.php'));
        $this->assertFileExists($this->projectPath('benchmarks/bin/comparator-run.php'));
        $this->assertFileExists($this->projectPath('benchmarks/bin/comparator-worker.php'));
        $this->assertFileExists($this->projectPath('benchmarks/bin/comparator-preflight.php'));
        $this->assertFileExists($this->projectPath('benchmarks/bin/capture-environment.php'));
        $this->assertFileExists($this->projectPath('benchmarks/bin/normalize-results.php'));
        $this->assertFileExists($this->projectPath('benchmarks/results/README.md'));

        $benchmarkManifest = $this->readJsonFile('benchmarks/composer.json');
        $this->assertArrayHasKey('phpbench/phpbench', $benchmarkManifest['require-dev']);
        $this->assertArrayHasKey('nyholm/psr7', $benchmarkManifest['require']);

        $rootManifest = $this->readJsonFile('composer.json');
        $this->assertPackageAbsentFromManifest('phpbench/phpbench', $rootManifest, 'composer.json');
        $this->assertPackageAbsentFromManifest('nyholm/psr7', $rootManifest, 'composer.json');

        foreach ($this->packageManifests() as $path) {
            $manifest = $this->readJsonFile($path);
            $this->assertPackageAbsentFromManifest('phpbench/phpbench', $manifest, $path);
            $this->assertPackageAbsentFromManifest('nyholm/psr7', $manifest, $path);
        }
    }

    public function testBenchmarkOutputPolicyIsScopedAndProductionSourceIsNotOwnedByHarness(): void
    {
        $gitignore = $this->readProjectFile('benchmarks/.gitignore');

        foreach ([
            '/vendor/',
            '/.phpbench/',
            '/results/local/',
            '/results/tmp/',
            '/profiles/',
        ] as $ignoredPath) {
            $this->assertStringContainsString($ignoredPath, $gitignore);
        }

        $phpbenchConfig = $this->readProjectFile('benchmarks/phpbench.json');
        $this->assertStringContainsString('"runner.path": "benchmarks"', $phpbenchConfig);
        $this->assertStringNotContainsString('packages/core/src', $phpbenchConfig);
        $this->assertStringNotContainsString('packages/http/src', $phpbenchConfig);

        foreach ($this->trackedFiles() as $file) {
            $normalized = str_replace('\\', '/', $file);
            $this->assertDoesNotMatchRegularExpression('#^packages/(contracts|core|http|module|plugin|testing|dev-tools)/src/.+Bench\.php$#', $normalized);
        }
    }

    public function testBenchmarkDocumentationRecordsProtocolAndNoMarketingClaim(): void
    {
        $readme = $this->readProjectFile('benchmarks/README.md');

        foreach ([
            'LOCAL / NON-CANONICAL BASELINE',
            'PHP 8.4',
            'OPcache enabled',
            'JIT disabled',
            'one-off stopwatch results are not performance evidence',
            'environment fingerprint',
            'optimization work',
            'Cross-framework comparison',
            'no current fastest-framework claim',
            'no current top-three performance claim',
            'controlled comparator execution',
            'subprocess per measured sample',
            'worker runtime identity only to prove child-process conformance',
            'fresh output directory',
            'without writing raw or normalized timing artifacts',
            'zero in-process subject warmups',
            'operations_per_sample',
            'per-sample raw hashes',
            'Candidate evidence',
            'Canonical reference evidence',
            'Phalcon Micro before handlers',
            'non-ranking policy',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $readme);
        }
    }

    public function testBenchmarkPublicDocsDoNotContainInternalReviewProvenance(): void
    {
        foreach ([
            'benchmarks/README.md',
            'benchmarks/results/README.md',
        ] as $path) {
            $content = $this->readProjectFile($path);

            foreach ([
                '/Codex/i',
                '/ChatGPT/i',
                '/AI-generated/i',
                '/assistant review/i',
                '/credits/i',
                '/usage limit/i',
                '/internal gate labels/i',
            ] as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $content, $path . ' must not expose internal review provenance.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function packageManifests(): array
    {
        return [
            'packages/contracts/composer.json',
            'packages/core/composer.json',
            'packages/dev-tools/composer.json',
            'packages/http/composer.json',
            'packages/module/composer.json',
            'packages/plugin/composer.json',
            'packages/testing/composer.json',
        ];
    }

    private function assertPackageAbsentFromManifest(string $package, array $manifest, string $path): void
    {
        $this->assertArrayNotHasKey($package, $manifest['require'] ?? [], $path . ' must not require ' . $package . '.');
        $this->assertArrayNotHasKey($package, $manifest['require-dev'] ?? [], $path . ' must not require-dev ' . $package . '.');
    }

    /**
     * @return list<string>
     */
    private function trackedFiles(): array
    {
        $output = [];
        $exitCode = 0;

        exec('git ls-files --cached --others --exclude-standard', $output, $exitCode);

        $this->assertSame(0, $exitCode, 'git ls-files should succeed.');

        sort($output);

        return $output;
    }

    private function projectPath(string $path): string
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function readProjectFile(string $path): string
    {
        $fullPath = $this->projectPath($path);
        $this->assertFileExists($fullPath, $path . ' should exist before it is read.');

        $content = file_get_contents($fullPath);
        $this->assertIsString($content);

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        $json = json_decode($this->readProjectFile($path), true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), $path . ' should contain valid JSON: ' . json_last_error_msg());
        $this->assertIsArray($json, $path . ' should decode to a JSON object.');

        return $json;
    }
}
