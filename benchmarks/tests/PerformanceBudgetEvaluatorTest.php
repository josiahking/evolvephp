<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests;

use Evolve\Benchmarks\Support\PerformanceBudgetEvaluator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PerformanceBudgetEvaluatorTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        $this->temporaryDirectories = [];
    }

    public function testWarmScenarioPassesWithinObservedEnvelope(): void
    {
        $result = $this->evaluateWithScenarioP50('http_static', 30.90);

        self::assertSame('pass', $result['status']);
        self::assertSame('pass', $result['scenarios']['http_static']['status']);
        self::assertFalse($result['blocking']);
    }

    public function testWarmScenarioWarnsBetweenObservedMaximumAndBlockingThreshold(): void
    {
        $result = $this->evaluateWithScenarioP50('http_static', 31.00);

        self::assertSame('warn', $result['status']);
        self::assertSame('warn', $result['scenarios']['http_static']['status']);
        self::assertFalse($result['blocking']);
    }

    public function testWarmScenarioFailsBeyondBlockingThreshold(): void
    {
        $result = $this->evaluateWithScenarioP50('http_static', 32.35);

        self::assertSame('fail', $result['status']);
        self::assertSame('fail', $result['scenarios']['http_static']['status']);
        self::assertTrue($result['blocking']);
    }

    public function testApplicationBootPassesWithinObservedEnvelope(): void
    {
        $result = $this->evaluateWithScenarioP50('application_boot', 1068.25);

        self::assertSame('pass', $result['status']);
        self::assertSame('pass', $result['scenarios']['application_boot']['status']);
        self::assertFalse($result['blocking']);
    }

    public function testApplicationBootWarnsBeyondObservedEnvelope(): void
    {
        $result = $this->evaluateWithScenarioP50('application_boot', 1068.26);

        self::assertSame('warn', $result['status']);
        self::assertSame('warn', $result['scenarios']['application_boot']['status']);
        self::assertFalse($result['blocking']);
    }

    public function testApplicationBootRemainsNonBlockingBeyondObservationBoundary(): void
    {
        $result = $this->evaluateWithScenarioP50('application_boot', 1148.456);

        self::assertSame('warn', $result['status']);
        self::assertSame('warn', $result['scenarios']['application_boot']['status']);
        self::assertFalse($result['blocking']);
        self::assertStringContainsString('observation boundary', implode(' ', $result['scenarios']['application_boot']['reasons']));
    }

    public function testEnvironmentFingerprintMismatchIsIncomparable(): void
    {
        $candidate = $this->candidateEvidence();
        $candidate['manifest']['execution_environment_fingerprint'] = str_repeat('0', 64);

        $this->assertIncomparable($candidate, 'execution environment fingerprint');
    }

    public function testMatrixLockAndProtocolMismatchesAreIncomparable(): void
    {
        $matrixCandidate = $this->candidateEvidence();
        $matrixCandidate['manifest']['matrix']['sha256'] = str_repeat('1', 64);
        $this->assertIncomparable($matrixCandidate, 'matrix');

        $lockCandidate = $this->candidateEvidence();
        $lockCandidate['results'][0]['comparator_lock_sha256'] = str_repeat('2', 64);
        $this->assertIncomparable($lockCandidate, 'lock');

        $protocolCandidate = $this->candidateEvidence();
        $protocolCandidate['manifest']['warmups'] = 4;
        $this->assertIncomparable($protocolCandidate, 'warmup');
    }

    public function testUnavailableFailedDirtyAndInsufficientEvidenceAreIncomparable(): void
    {
        $unavailable = $this->candidateEvidence();
        $unavailable['results'][0]['availability'] = 'unavailable';
        $this->assertIncomparable($unavailable, 'available');

        $failed = $this->candidateEvidence();
        $failed['results'][0]['availability'] = 'failed';
        $this->assertIncomparable($failed, 'available');

        $dirty = $this->candidateEvidence();
        $dirty['results'][0]['source_dirty'] = true;
        $this->assertIncomparable($dirty, 'dirty');

        $insufficient = $this->candidateEvidence();
        $insufficient['results'][0]['baseline_result']['scenarios'][0]['sample_count'] = 99;
        $this->assertIncomparable($insufficient, 'sample count');
    }

    public function testCleanlinessEvidenceMustBePositivelyProven(): void
    {
        $manifestDirty = $this->candidateEvidence();
        $manifestDirty['manifest']['source']['dirty'] = true;
        $this->assertIncomparable($manifestDirty, 'dirty');

        $manifestMissing = $this->candidateEvidence();
        unset($manifestMissing['manifest']['source']['dirty']);
        $this->assertIncomparable($manifestMissing, 'dirty');

        $manifestWrongType = $this->candidateEvidence();
        $manifestWrongType['manifest']['source']['dirty'] = 'false';
        $this->assertIncomparable($manifestWrongType, 'dirty');

        $normalizedDirty = $this->candidateEvidence();
        $normalizedDirty['results'][0]['source_dirty'] = true;
        $this->assertIncomparable($normalizedDirty, 'dirty');

        $normalizedMissing = $this->candidateEvidence();
        unset($normalizedMissing['results'][0]['source_dirty']);
        $this->assertIncomparable($normalizedMissing, 'dirty');

        $normalizedWrongType = $this->candidateEvidence();
        $normalizedWrongType['results'][0]['source_dirty'] = 'false';
        $this->assertIncomparable($normalizedWrongType, 'dirty');
    }

    public function testCandidateSourceShaMustBeConsistentAcrossManifestAndNormalizedResults(): void
    {
        $normalizedMismatch = $this->candidateEvidence();
        $normalizedMismatch['results'][0]['source_evolvephp_sha'] = str_repeat('1', 40);
        $this->assertIncomparable($normalizedMismatch, 'source SHA');

        $baselineMismatch = $this->candidateEvidence();
        $baselineMismatch['results'][0]['baseline_result']['source_sha'] = str_repeat('2', 40);
        $this->assertIncomparable($baselineMismatch, 'source SHA');

        $missingNormalized = $this->candidateEvidence();
        unset($missingNormalized['results'][0]['source_evolvephp_sha']);
        $this->assertIncomparable($missingNormalized, 'source SHA');

        $invalidNormalized = $this->candidateEvidence();
        $invalidNormalized['results'][0]['source_evolvephp_sha'] = 'not-a-sha';
        $this->assertIncomparable($invalidNormalized, 'source SHA');
    }

    public function testMissingRequiredScenarioAndMalformedResultAreIncomparable(): void
    {
        $missing = $this->candidateEvidence();
        $missing['results'] = array_values(array_filter(
            $missing['results'],
            static fn(array $result): bool => $result['scenario_id'] !== 'http_static',
        ));
        $this->assertIncomparable($missing, 'missing required scenario');

        $malformed = $this->candidateEvidence();
        $malformed['results'][0]['baseline_result'] = null;
        $this->assertIncomparable($malformed, 'normalized result');
    }

    public function testRepeatedWarmOperationsProtocolMismatchIsIncomparable(): void
    {
        $candidate = $this->candidateEvidence();

        foreach ($candidate['results'] as &$result) {
            if ($result['scenario_id'] === 'http_repeated_warm') {
                $result['baseline_result']['scenarios'][0]['operations_per_sample'] = 24;
            }
        }

        $this->assertIncomparable($candidate, 'operations per sample');
    }

    public function testNormalizedUnitSampleCountAndP50ProtocolMustMatchExactly(): void
    {
        $wrongUnit = $this->candidateEvidence();
        $wrongUnit['results'][0]['baseline_result']['scenarios'][0]['unit'] = 'nanoseconds';
        $this->assertIncomparable($wrongUnit, 'unit');

        $tooFewSamples = $this->candidateEvidence();
        $tooFewSamples['results'][0]['baseline_result']['scenarios'][0]['sample_count'] = 99;
        $this->assertIncomparable($tooFewSamples, 'sample count');

        $tooManySamples = $this->candidateEvidence();
        $tooManySamples['results'][0]['baseline_result']['scenarios'][0]['sample_count'] = 101;
        $this->assertIncomparable($tooManySamples, 'sample count');

        $zeroP50 = $this->candidateEvidence();
        $zeroP50['results'][0]['baseline_result']['scenarios'][0]['p50'] = 0.0;
        $this->assertIncomparable($zeroP50, 'p50');

        $negativeP50 = $this->candidateEvidence();
        $negativeP50['results'][0]['baseline_result']['scenarios'][0]['p50'] = -1.0;
        $this->assertIncomparable($negativeP50, 'p50');

        $nonFiniteP50 = $this->candidateEvidence();
        $nonFiniteP50['results'][0]['baseline_result']['scenarios'][0]['p50'] = INF;
        $this->assertIncomparable($nonFiniteP50, 'p50');

        $unavailableP50Status = $this->candidateEvidence();
        $unavailableP50Status['results'][0]['baseline_result']['scenarios'][0]['p50_status'] = 'insufficient_samples';
        $this->assertIncomparable($unavailableP50Status, 'p50 status');

        $wrongRepeatedWarmUnit = $this->candidateEvidence();
        foreach ($wrongRepeatedWarmUnit['results'] as &$result) {
            if ($result['scenario_id'] === 'http_repeated_warm') {
                $result['baseline_result']['scenarios'][0]['unit'] = 'microseconds';
            }
        }
        unset($result);
        $this->assertIncomparable($wrongRepeatedWarmUnit, 'unit');
    }

    public function testMalformedBudgetReferenceDataIsAValidationError(): void
    {
        $budget = $this->budget();
        unset($budget['scenarios']['http_static']['reference_p50_microseconds']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('performance budget');

        (new PerformanceBudgetEvaluator())->evaluate($budget, $this->candidateEvidence());
    }

    public function testUnsupportedAndIncoherentBudgetPolicyIsAValidationError(): void
    {
        $unsupportedSchema = $this->budget();
        $unsupportedSchema['schema_version'] = 'evolvephp.performance-budget.v0';
        $this->assertInvalidBudget($unsupportedSchema);

        $wrongCalibrationCount = $this->budget();
        $wrongCalibrationCount['calibration']['run_count'] = 2;
        $this->assertInvalidBudget($wrongCalibrationCount);

        $referenceAboveMaximum = $this->budget();
        $referenceAboveMaximum['scenarios']['http_static']['reference_p50_microseconds'] = 31.0;
        $this->assertInvalidBudget($referenceAboveMaximum);

        $wrongMedian = $this->budget();
        $wrongMedian['scenarios']['http_static']['reference_p50_microseconds'] = 30.7;
        $this->assertInvalidBudget($wrongMedian);

        $wrongRange = $this->budget();
        $wrongRange['scenarios']['http_static']['cross_run_range_percent'] = 99.0;
        $this->assertInvalidBudget($wrongRange);

        $blockingThresholdBelowObservedMax = $this->budget();
        $blockingThresholdBelowObservedMax['scenarios']['http_static']['blocking_threshold_p50_microseconds'] = 30.8;
        $this->assertInvalidBudget($blockingThresholdBelowObservedMax);
    }

    public function testBudgetMustContainExactlyTheSupportedScenarioSet(): void
    {
        $missingHttpStatic = $this->budget();
        unset($missingHttpStatic['scenarios']['http_static']);
        $this->assertInvalidBudget($missingHttpStatic);

        $missingApplicationBoot = $this->budget();
        unset($missingApplicationBoot['scenarios']['application_boot']);
        $this->assertInvalidBudget($missingApplicationBoot);

        $extraScenario = $this->budget();
        $extraScenario['scenarios']['unknown_scenario'] = $extraScenario['scenarios']['http_static'];
        $this->assertInvalidBudget($extraScenario);
    }

    public function testBudgetComparatorIdentityContractIsPinned(): void
    {
        $manifestSchemaDrift = $this->budget();
        $manifestSchemaDrift['comparison_identity']['manifest_schema_version'] = 'evolvephp.comparator.evidence-manifest.v2';
        $this->assertInvalidBudget($manifestSchemaDrift);

        $resultSchemaDrift = $this->budget();
        $resultSchemaDrift['comparison_identity']['comparator_result_schema_version'] = 'evolvephp.comparator.result.v2';
        $this->assertInvalidBudget($resultSchemaDrift);

        $comparatorIdDrift = $this->budget();
        $comparatorIdDrift['comparison_identity']['comparator_id'] = 'laravel';
        $this->assertInvalidBudget($comparatorIdDrift);
    }

    public function testAggregateStatePrecedenceIsDeterministic(): void
    {
        $warn = $this->candidateEvidence(['http_static' => 31.00]);
        self::assertSame('warn', (new PerformanceBudgetEvaluator())->evaluate($this->budget(), $warn)['status']);

        $fail = $this->candidateEvidence(['http_static' => 32.35, 'application_boot' => 1200.00]);
        self::assertSame('fail', (new PerformanceBudgetEvaluator())->evaluate($this->budget(), $fail)['status']);

        $incomparable = $this->candidateEvidence(['http_static' => 32.35]);
        $incomparable['manifest']['execution_environment_fingerprint'] = str_repeat('f', 64);
        self::assertSame('incomparable', (new PerformanceBudgetEvaluator())->evaluate($this->budget(), $incomparable)['status']);
    }

    public function testCliCandidateOptionAcceptsDocumentedSpaceSeparatedDirectory(): void
    {
        $budgetPath = $this->writeBudgetFile($this->budget());
        $candidateDir = $this->writeCandidateDirectory($this->candidateEvidence());

        $result = $this->runBudgetCli([
            '--budget',
            $budgetPath,
            '--candidate',
            $candidateDir,
        ]);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('"status": "pass"', $result['stdout']);
    }

    public function testCliReferenceValidationFailsWhenDuplicatedSummaryPolicyDrifts(): void
    {
        $thresholdDrift = $this->trackedBudget();
        $thresholdDrift['scenarios']['http_static']['blocking_threshold_p50_microseconds'] = 32.35;
        $thresholdResult = $this->runBudgetCli([
            '--budget',
            $this->writeBudgetFile($thresholdDrift),
            '--validate-reference',
        ]);
        self::assertSame(2, $thresholdResult['exit_code']);

        $protocolDrift = $this->trackedBudget();
        $protocolDrift['sample_protocol']['request_count'] = 24;
        $protocolDrift['scenarios']['http_repeated_warm']['observed_p50_microseconds'] = [26.662, 26.652, 26.54];
        $protocolResult = $this->runBudgetCli([
            '--budget',
            $this->writeBudgetFile($protocolDrift),
            '--validate-reference',
        ]);
        self::assertSame(2, $protocolResult['exit_code']);

        $runDrift = $this->trackedBudget();
        $runDrift['scenarios']['http_static']['observed_p50_microseconds'] = [30.9, 30.8, 30.8];
        $runDrift['scenarios']['http_static']['reference_p50_microseconds'] = 30.8;
        $runDrift['scenarios']['http_static']['observed_maximum_p50_microseconds'] = 30.9;
        $runDrift['scenarios']['http_static']['cross_run_range_percent'] = 0.32467532467532;
        $runResult = $this->runBudgetCli([
            '--budget',
            $this->writeBudgetFile($runDrift),
            '--validate-reference',
        ]);
        self::assertSame(2, $runResult['exit_code']);
    }

    public function testCliRejectsNormalizedArtifactHashMismatchAndTraversal(): void
    {
        $budgetPath = $this->writeBudgetFile($this->budget());

        $mismatchedHashDir = $this->writeCandidateDirectory($this->candidateEvidence(), corruptFirstHash: true);
        $mismatchedHash = $this->runBudgetCli([
            '--budget',
            $budgetPath,
            '--candidate',
            $mismatchedHashDir,
        ]);
        self::assertSame(2, $mismatchedHash['exit_code']);

        $traversalDir = $this->writeCandidateDirectory($this->candidateEvidence(), traversalFirstPath: true);
        $traversal = $this->runBudgetCli([
            '--budget',
            $budgetPath,
            '--candidate',
            $traversalDir,
        ]);
        self::assertSame(2, $traversal['exit_code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluateWithScenarioP50(string $scenarioId, float $p50): array
    {
        return (new PerformanceBudgetEvaluator())->evaluate($this->budget(), $this->candidateEvidence([$scenarioId => $p50]));
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function assertIncomparable(array $candidate, string $reasonFragment): void
    {
        $result = (new PerformanceBudgetEvaluator())->evaluate($this->budget(), $candidate);

        self::assertSame('incomparable', $result['status']);
        self::assertFalse($result['blocking']);
        self::assertStringContainsString($reasonFragment, implode(' ', $result['reasons']));
    }

    /**
     * @param array<string, mixed> $budget
     */
    private function assertInvalidBudget(array $budget): void
    {
        try {
            (new PerformanceBudgetEvaluator())->evaluate($budget, $this->candidateEvidence());
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('performance budget', $exception->getMessage());

            return;
        }

        self::fail('Expected invalid performance budget.');
    }

    /**
     * @return array<string, mixed>
     */
    private function budget(): array
    {
        return [
            'schema_version' => 'evolvephp.performance-budget.v1',
            'baseline_source_sha' => str_repeat('a', 40),
            'calibration' => [
                'source' => 'accepted controlled EvolvePHP-only regression calibration',
                'run_count' => 3,
                'sample_count' => 100,
                'primary_metric' => 'p50',
                'raw_evidence_policy' => 'full raw controlled evidence is retained outside this repository',
            ],
            'canonical_runtime_policy' => [
                'php_version' => '8.4.25',
                'opcache_cli_enabled' => true,
                'jit_enabled' => false,
            ],
            'warmup_policy' => [
                'warm_http_subject_warmups' => 5,
                'application_boot_measured_worker_in_process_warmups' => 0,
                'application_boot_discarded_worker_processes_per_measured_sample' => 1,
            ],
            'comparison_identity' => [
                'manifest_schema_version' => 'evolvephp.comparator.evidence-manifest.v1',
                'comparator_result_schema_version' => 'evolvephp.comparator.result.v1',
                'comparator_id' => 'evolvephp',
                'execution_environment_fingerprint' => str_repeat('b', 64),
                'matrix_sha256' => str_repeat('c', 64),
                'comparator_lock_sha256' => str_repeat('d', 64),
                'fixture_identity_hash' => str_repeat('e', 64),
            ],
            'sample_protocol' => [
                'sample_count' => 100,
                'warmups' => 5,
                'request_count' => 25,
                'process_isolation_model' => 'subprocess_per_measured_sample',
                'repeated_warm_operations_per_sample' => 25,
                'boot_protocol' => [
                    'discarded_worker_processes_per_measured_sample' => 1,
                    'sample_order' => 'rotating_round_robin',
                    'measured_worker_in_process_warmups' => 0,
                    'outlier_policy' => 'retain_all_measured_samples',
                    'primary_central_statistic' => 'p50',
                ],
            ],
            'primary_metric' => 'p50',
            'scenarios' => [
                'application_boot' => [
                    'mode' => 'monitor',
                    'observed_p50_microseconds' => [1068.25, 1042.40, 1044.05],
                    'reference_p50_microseconds' => 1044.05,
                    'observed_maximum_p50_microseconds' => 1068.25,
                    'cross_run_range_percent' => 2.4798541826554,
                    'observation_threshold_p50_microseconds' => 1148.455,
                    'diagnostic_rsd_percent' => [29.76929761631, 18.666326692293, 25.872643772215],
                ],
                'http_static' => [
                    'mode' => 'blocking',
                    'observed_p50_microseconds' => [30.90, 30.80, 30.70],
                    'reference_p50_microseconds' => 30.80,
                    'observed_maximum_p50_microseconds' => 30.90,
                    'cross_run_range_percent' => 0.6514657980456,
                    'blocking_threshold_p50_microseconds' => 32.34,
                ],
                'http_parameterized' => [
                    'mode' => 'blocking',
                    'observed_p50_microseconds' => [34.40, 34.40, 34.50],
                    'reference_p50_microseconds' => 34.40,
                    'observed_maximum_p50_microseconds' => 34.50,
                    'cross_run_range_percent' => 0.29069767441861,
                    'blocking_threshold_p50_microseconds' => 36.12,
                ],
                'http_middleware' => [
                    'mode' => 'blocking',
                    'observed_p50_microseconds' => [37.20, 37.20, 37.30],
                    'reference_p50_microseconds' => 37.20,
                    'observed_maximum_p50_microseconds' => 37.30,
                    'cross_run_range_percent' => 0.26881720430106,
                    'blocking_threshold_p50_microseconds' => 39.06,
                ],
                'http_not_found' => [
                    'mode' => 'blocking',
                    'observed_p50_microseconds' => [23.90, 23.80, 23.80],
                    'reference_p50_microseconds' => 23.80,
                    'observed_maximum_p50_microseconds' => 23.90,
                    'cross_run_range_percent' => 0.42016806722688,
                    'blocking_threshold_p50_microseconds' => 24.99,
                ],
                'http_repeated_warm' => [
                    'mode' => 'blocking',
                    'observed_p50_microseconds' => [26.662, 26.652, 26.540],
                    'reference_p50_microseconds' => 26.652,
                    'observed_maximum_p50_microseconds' => 26.662,
                    'cross_run_range_percent' => 0.45968349660889,
                    'blocking_threshold_p50_microseconds' => 27.9846,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trackedBudget(): array
    {
        $budget = json_decode((string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'budgets' . DIRECTORY_SEPARATOR . 'performance-budget.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($budget);

        return $budget;
    }

    /**
     * @param array<string, float> $p50Overrides
     * @return array<string, mixed>
     */
    private function candidateEvidence(array $p50Overrides = []): array
    {
        $budget = $this->budget();
        $results = [];

        foreach ($budget['scenarios'] as $scenarioId => $policy) {
            $results[] = $this->scenarioResult(
                $scenarioId,
                $p50Overrides[$scenarioId] ?? (float) $policy['observed_maximum_p50_microseconds'],
            );
        }

        return [
            'manifest' => [
                'schema_version' => 'evolvephp.comparator.evidence-manifest.v1',
                'status' => 'completed',
                'source' => ['git_sha' => str_repeat('f', 40), 'dirty' => false],
                'execution_environment_fingerprint' => str_repeat('b', 64),
                'matrix' => ['sha256' => str_repeat('c', 64)],
                'process_isolation' => ['model' => 'subprocess_per_measured_sample'],
                'samples' => 100,
                'warmups' => 5,
                'request_count' => 25,
                'boot_protocol' => $budget['sample_protocol']['boot_protocol'],
            ],
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scenarioResult(string $scenarioId, float $p50): array
    {
        return [
            'comparator_schema_version' => 'evolvephp.comparator.result.v1',
            'comparator_id' => 'evolvephp',
            'scenario_id' => $scenarioId,
            'availability' => 'available',
            'source_evolvephp_sha' => str_repeat('f', 40),
            'source_dirty' => false,
            'execution_environment_fingerprint' => str_repeat('b', 64),
            'matrix_sha256' => str_repeat('c', 64),
            'comparator_lock_sha256' => str_repeat('d', 64),
            'fixture_identity_hash' => str_repeat('e', 64),
            'baseline_result' => [
                'schema_version' => 'evolvephp.benchmark.results.v1',
                'source_sha' => str_repeat('f', 40),
                'scenarios' => [
                    [
                        'id' => $scenarioId,
                        'sample_count' => 100,
                        'unit' => $scenarioId === 'http_repeated_warm' ? 'per_operation_microseconds' : 'microseconds',
                        'p50' => $p50,
                        'p50_status' => 'available',
                        'p95' => $p50 + 1.0,
                        'p99' => $p50 + 2.0,
                        'mean' => $p50,
                        'relative_standard_deviation_percent' => 1.0,
                        'throughput_per_second' => 1_000_000 / $p50,
                        'operations_per_sample' => $scenarioId === 'http_repeated_warm' ? 25 : 1,
                        'memory' => ['peak_bytes' => 1024],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $budget
     */
    private function writeBudgetFile(array $budget): string
    {
        $directory = $this->temporaryDirectory();
        $path = $directory . DIRECTORY_SEPARATOR . 'performance-budget.json';
        file_put_contents($path, json_encode($budget, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

        return $path;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function writeCandidateDirectory(
        array $candidate,
        bool $corruptFirstHash = false,
        bool $traversalFirstPath = false,
    ): string {
        $directory = $this->temporaryDirectory();
        $normalizedDirectory = $directory . DIRECTORY_SEPARATOR . 'normalized';
        mkdir($normalizedDirectory, 0777, true);
        $manifest = $candidate['manifest'];
        $manifest['results'] = [];

        foreach ($candidate['results'] as $index => $result) {
            $path = 'normalized/' . $result['scenario_id'] . '.json';
            $fullPath = $normalizedDirectory . DIRECTORY_SEPARATOR . $result['scenario_id'] . '.json';

            if ($index === 0 && $traversalFirstPath) {
                $outsideDirectory = $this->temporaryDirectory();
                $path = '../' . basename($outsideDirectory) . '/outside-normalized.json';
                $fullPath = $outsideDirectory . DIRECTORY_SEPARATOR . 'outside-normalized.json';
            }

            file_put_contents($fullPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

            $manifest['results'][] = [
                'scenario_id' => $result['scenario_id'],
                'normalized_result' => [
                    'path' => $path,
                    'sha256' => $index === 0 && $corruptFirstHash
                        ? str_repeat('0', 64)
                        : hash_file('sha256', $fullPath),
                ],
            ];
        }

        file_put_contents($directory . DIRECTORY_SEPARATOR . 'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

        return $directory;
    }

    /**
     * @param list<string> $arguments
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runBudgetCli(array $arguments): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'performance-budget.php'], $arguments);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evolvephp-budget-test-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($path);
    }
}
