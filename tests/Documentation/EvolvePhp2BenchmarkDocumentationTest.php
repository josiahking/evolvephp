<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2BenchmarkDocumentationTest extends TestCase
{
    public function testBenchmarkResultsDocumentationDescribesControlledEvidenceArtifacts(): void
    {
        $content = $this->readProjectFile('benchmarks/results/README.md');

        foreach ([
            'results/local/comparator-candidate/',
            'manifest.json',
            'raw/',
            'normalized/',
            'source SHA and dirty state',
            'matrix hash',
            'subprocess command lines',
            'comparator lock hash',
            'execution environment identity/fingerprint',
            'worker runtime identity',
            'per-sample raw hashes',
            'fresh output directory',
            'stale output is never deleted implicitly',
            'preflight rejects the controlled lane on a fresh path',
            'one subprocess per measured sample',
            'one separate discarded application_boot worker',
            'rotating round-robin',
            'zero in-process warmups',
            'discarded workers are excluded from statistics',
            'p50 is the primary central comparison statistic for application_boot',
            'mean, p95, p99 and relative standard deviation remain visible',
            'No measured sample is removed as an outlier',
            'actual worker-order provenance',
            'operations_per_sample',
            'Candidate output from a dirty or uncommitted worktree is not canonical reference evidence',
            'controlled PHP 8.4.25 lane',
            'results/reference/performance-summary.json',
            'results/reference/performance-report.md',
            'performance-budget.php',
            'pass',
            'warn',
            'fail',
            'incomparable',
            'monitor-only',
            'Warm HTTP p50 thresholds are blocking only for controlled evidence',
            'must not run the canonical 100-sample comparator timing suite',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $content);
        }
    }

    public function testPublicPerformanceReportDocumentsNonRankingReferencePolicy(): void
    {
        $content = $this->readProjectFile('benchmarks/results/reference/performance-report.md');

        foreach ([
            'Methodology',
            'Regression Calibration',
            'Cross-Framework Reference',
            'CI And Regression Policy',
            'Limitations',
            'c62cecb16cb4fcdc93bfbb0188a4b63d8cf704ce',
            'debfb4228c4d652a5f6d0bdc4ff0f3a9c0a6c1c2',
            '9c06a992d7f01cb7096a60b893f33a34aff7b2a86fba157c8b879d9ac55457a2',
            'The warm HTTP p50 results are competitive in this controlled matrix.',
            'Cold boot is the visible comparative gap',
            'The current data does not prove that framework architecture is the definitive cause',
            'This is a non-ranking report.',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $content);
        }

        foreach ([
            'EvolvePHP is generally faster',
            'EvolvePHP is the fastest framework',
            'top-three framework overall',
            'architecture is definitively the cause',
        ] as $forbiddenClaim) {
            $this->assertStringNotContainsString($forbiddenClaim, $content);
        }
    }

    public function testReferenceSummaryMatchesBudgetScenarioPolicy(): void
    {
        $budget = $this->readJsonFile('benchmarks/budgets/performance-budget.json');
        $summary = $this->readJsonFile('benchmarks/results/reference/performance-summary.json');

        $this->assertSame($budget['baseline_source_sha'], $summary['regression_baseline_source_sha']);
        $this->assertSame($budget['comparison_identity']['execution_environment_fingerprint'], $summary['canonical_environment_fingerprint']);

        foreach ($budget['scenarios'] as $scenarioId => $policy) {
            $this->assertArrayHasKey($scenarioId, $summary['scenarios']);
            $this->assertSame($policy['reference_p50_microseconds'], $summary['scenarios'][$scenarioId]['reference_median_p50_microseconds']);
            $this->assertSame($policy['observed_maximum_p50_microseconds'], $summary['scenarios'][$scenarioId]['observed_maximum_p50_microseconds']);
            $this->assertSame($policy['mode'], $summary['scenarios'][$scenarioId]['budget_classification']);
        }
    }

    private function readProjectFile(string $path): string
    {
        $fullPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
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
