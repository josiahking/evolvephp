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
        $this->assertFileExists($this->projectPath('benchmarks/bin/performance-budget.php'));
        $this->assertFileExists($this->projectPath('benchmarks/budgets/performance-budget.json'));
        $this->assertFileExists($this->projectPath('benchmarks/results/README.md'));
        $this->assertFileExists($this->projectPath('benchmarks/results/reference/performance-summary.json'));
        $this->assertFileExists($this->projectPath('benchmarks/results/reference/performance-report.md'));

        $benchmarkManifest = $this->readJsonFile('benchmarks/composer.json');
        $this->assertArrayHasKey('phpbench/phpbench', $benchmarkManifest['require-dev']);
        $this->assertArrayHasKey('nyholm/psr7', $benchmarkManifest['require']);
        $this->assertArrayHasKey('budget:validate', $benchmarkManifest['scripts']);
        $this->assertArrayHasKey('test:budget', $benchmarkManifest['scripts']);
        $this->assertArrayHasKey('ci:policy', $benchmarkManifest['scripts']);

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
            'performance-budget.php',
            'pass',
            'warn',
            'fail',
            'incomparable',
            'application_boot',
            'monitor-only',
            'warm HTTP p50',
            'Shared GitHub-hosted runners',
            'Raw 100-sample process records',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $readme);
        }
    }

    public function testPerformanceBudgetReferenceUsesDerivedComparatorIdentities(): void
    {
        $budget = $this->readJsonFile('benchmarks/budgets/performance-budget.json');
        $matrixPath = $this->projectPath('benchmarks/comparators/matrix.json');
        $matrix = $this->readJsonFile('benchmarks/comparators/matrix.json');
        $evolvePhpComparator = $this->evolvePhpComparator($matrix);

        $this->assertSame('evolvephp.performance-budget.v1', $budget['schema_version']);
        $this->assertSame('c62cecb16cb4fcdc93bfbb0188a4b63d8cf704ce', $budget['baseline_source_sha']);
        $this->assertSame('9c06a992d7f01cb7096a60b893f33a34aff7b2a86fba157c8b879d9ac55457a2', $budget['comparison_identity']['execution_environment_fingerprint']);
        $this->assertSame(hash_file('sha256', $matrixPath), $budget['comparison_identity']['matrix_sha256']);
        $this->assertSame($evolvePhpComparator['lock_sha256'], $budget['comparison_identity']['comparator_lock_sha256']);
        $this->assertSame(
            hash_file('sha256', $this->projectPath('benchmarks/comparators/evolvephp/composer.lock')),
            $budget['comparison_identity']['comparator_lock_sha256'],
        );
        $this->assertSame($this->fixtureIdentityHash($evolvePhpComparator), $budget['comparison_identity']['fixture_identity_hash']);

        $this->assertSame('8.4.25', $budget['canonical_runtime_policy']['php_version']);
        $this->assertTrue($budget['canonical_runtime_policy']['opcache_cli_enabled']);
        $this->assertFalse($budget['canonical_runtime_policy']['jit_enabled']);
        $this->assertSame(100, $budget['sample_protocol']['sample_count']);
        $this->assertSame(5, $budget['sample_protocol']['warmups']);
        $this->assertSame(25, $budget['sample_protocol']['request_count']);
        $this->assertSame(25, $budget['sample_protocol']['repeated_warm_operations_per_sample']);
        $this->assertSame(0, $budget['warmup_policy']['application_boot_measured_worker_in_process_warmups']);
        $this->assertSame(1, $budget['warmup_policy']['application_boot_discarded_worker_processes_per_measured_sample']);
        $this->assertSame(1, $budget['sample_protocol']['boot_protocol']['discarded_worker_processes_per_measured_sample']);
        $this->assertSame('rotating_round_robin', $budget['sample_protocol']['boot_protocol']['sample_order']);
        $this->assertSame(0, $budget['sample_protocol']['boot_protocol']['measured_worker_in_process_warmups']);
        $this->assertSame('retain_all_measured_samples', $budget['sample_protocol']['boot_protocol']['outlier_policy']);
        $this->assertSame('p50', $budget['sample_protocol']['boot_protocol']['primary_central_statistic']);
        $this->assertSame('p50', $budget['primary_metric']);

        $this->assertScenarioPolicy($budget, 'application_boot', 'monitor', 1044.05, 1068.25, 1148.455);
        $this->assertScenarioPolicy($budget, 'http_static', 'blocking', 30.8, 30.9, 32.34);
        $this->assertScenarioPolicy($budget, 'http_parameterized', 'blocking', 34.4, 34.5, 36.12);
        $this->assertScenarioPolicy($budget, 'http_middleware', 'blocking', 37.2, 37.3, 39.06);
        $this->assertScenarioPolicy($budget, 'http_not_found', 'blocking', 23.8, 23.9, 24.99);
        $this->assertScenarioPolicy($budget, 'http_repeated_warm', 'blocking', 26.652, 26.662, 27.9846);
    }

    public function testQualityWorkflowHasCiSafeBenchmarkPolicyJob(): void
    {
        $workflow = $this->readProjectFile('.github/workflows/quality.yml');

        $this->assertStringContainsString('Benchmark policy (PHP 8.4)', $workflow);
        $this->assertStringContainsString('composer validate --working-dir=benchmarks --strict --check-lock', $workflow);
        $this->assertStringContainsString('composer install --working-dir=benchmarks --no-interaction --no-progress --prefer-dist', $workflow);
        $this->assertStringContainsString('composer --working-dir=benchmarks ci:policy', $workflow);
        $this->assertStringNotContainsString('benchmarks/bin/comparator-run.php', $workflow);
        $this->assertStringNotContainsString('comparator:run', $workflow);
    }

    public function testBenchmarkPublicDocsDoNotContainInternalReviewProvenance(): void
    {
        foreach ([
            'benchmarks/README.md',
            'benchmarks/results/README.md',
            'benchmarks/results/reference/performance-report.md',
        ] as $path) {
            $content = $this->readProjectFile($path);

            foreach ([
                '/\bphase\b/i',
                '/\bmaintainer\b/i',
                '/Codex/i',
                '/ChatGPT/i',
                '/\bagent\b/i',
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

    /**
     * @param array<string, mixed> $manifest
     */
    private function assertPackageAbsentFromManifest(string $package, array $manifest, string $path): void
    {
        $this->assertArrayNotHasKey($package, $manifest['require'] ?? [], $path . ' must not require ' . $package . '.');
        $this->assertArrayNotHasKey($package, $manifest['require-dev'] ?? [], $path . ' must not require-dev ' . $package . '.');
    }

    /**
     * @param array<string, mixed> $budget
     */
    private function assertScenarioPolicy(
        array $budget,
        string $scenarioId,
        string $mode,
        float $referenceP50,
        float $observedMaximumP50,
        float $thresholdP50,
    ): void {
        $this->assertArrayHasKey($scenarioId, $budget['scenarios']);
        $scenario = $budget['scenarios'][$scenarioId];

        $this->assertSame($mode, $scenario['mode']);
        $this->assertSame($referenceP50, $scenario['reference_p50_microseconds']);
        $this->assertSame($observedMaximumP50, $scenario['observed_maximum_p50_microseconds']);

        if ($mode === 'monitor') {
            $this->assertSame($thresholdP50, $scenario['observation_threshold_p50_microseconds']);

            return;
        }

        $this->assertSame($thresholdP50, $scenario['blocking_threshold_p50_microseconds']);
    }

    /**
     * @param array<string, mixed> $matrix
     * @return array<string, mixed>
     */
    private function evolvePhpComparator(array $matrix): array
    {
        foreach ($matrix['comparators'] ?? [] as $comparator) {
            if (is_array($comparator) && ($comparator['id'] ?? null) === 'evolvephp') {
                return $comparator;
            }
        }

        $this->fail('EvolvePHP comparator should exist in the comparator matrix.');
    }

    /**
     * @param array<string, mixed> $comparator
     */
    private function fixtureIdentityHash(array $comparator): string
    {
        $fields = [
            'comparator_id' => $comparator['id'] ?? null,
            'configuration' => $comparator['configuration'] ?? [],
            'fixture_version' => $comparator['fixture_version'] ?? null,
            'framework_name' => $comparator['name'] ?? null,
            'framework_version' => $comparator['framework_version'] ?? null,
            'lock_hash' => $comparator['lock_sha256'] ?? null,
        ];
        $this->sortRecursive($fields);

        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<mixed> $value
     */
    private function sortRecursive(array &$value): void
    {
        foreach ($value as &$entry) {
            if (is_array($entry)) {
                $this->sortRecursive($entry);
            }
        }

        if (!array_is_list($value)) {
            ksort($value);
        }
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
