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
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $content);
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
}
