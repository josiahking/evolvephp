<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Support;

use InvalidArgumentException;

final class PerformanceBudgetEvaluator
{
    public const EVALUATION_SCHEMA_VERSION = 'evolvephp.performance-budget.evaluation.v1';

    private const BUDGET_SCHEMA_VERSION = 'evolvephp.performance-budget.v1';

    private const FLOAT_TOLERANCE = 0.000000001;

    /**
     * @var list<string>
     */
    private const REQUIRED_SCENARIOS = [
        'application_boot',
        'http_static',
        'http_parameterized',
        'http_middleware',
        'http_not_found',
        'http_repeated_warm',
    ];

    /**
     * @param array<string, mixed> $budget
     * @param array<string, mixed> $candidateEvidence
     * @return array<string, mixed>
     */
    public function evaluate(array $budget, array $candidateEvidence): array
    {
        $this->assertValidBudget($budget);

        $globalReasons = $this->globalIncomparabilityReasons($budget, $candidateEvidence);
        $manifestSourceSha = $this->manifestSourceSha($candidateEvidence['manifest'] ?? null);
        $scenarioResults = [];

        foreach ($budget['scenarios'] as $scenarioId => $policy) {
            $candidate = $this->candidateResultForScenario($candidateEvidence, (string) $scenarioId);
            $scenarioResults[(string) $scenarioId] = $this->evaluateScenario(
                (string) $scenarioId,
                $policy,
                $budget,
                $candidate,
                $manifestSourceSha,
            );
        }

        foreach ($scenarioResults as $scenarioResult) {
            if (($scenarioResult['status'] ?? null) === 'incomparable') {
                foreach ($scenarioResult['reasons'] as $reason) {
                    $globalReasons[] = (string) $reason;
                }
            }
        }

        $status = $globalReasons !== []
            ? 'incomparable'
            : $this->aggregateStatus(array_column($scenarioResults, 'status'));

        return [
            'schema_version' => self::EVALUATION_SCHEMA_VERSION,
            'status' => $status,
            'blocking' => $status === 'fail',
            'primary_metric' => $budget['primary_metric'],
            'scenarios' => $scenarioResults,
            'reasons' => array_values(array_unique($globalReasons)),
        ];
    }

    /**
     * @param array<string, mixed> $budget
     */
    public function assertValidBudget(array $budget): void
    {
        if (($budget['schema_version'] ?? null) !== self::BUDGET_SCHEMA_VERSION) {
            throw new InvalidArgumentException('Invalid performance budget: unsupported schema_version.');
        }

        $this->requireSha($budget, 'baseline_source_sha', 40);
        $this->requireExactString($budget, 'primary_metric', 'p50');

        $calibration = $this->requireArray($budget, 'calibration');
        $this->requireString($calibration, 'source');
        $this->requireString($calibration, 'raw_evidence_policy');
        $this->requireExactPositiveInteger($calibration, 'run_count', 3);
        $this->requireExactString($calibration, 'primary_metric', 'p50');

        $runtime = $this->requireArray($budget, 'canonical_runtime_policy');
        $this->requireString($runtime, 'php_version');
        $this->requireBoolean($runtime, 'opcache_cli_enabled');
        $this->requireBoolean($runtime, 'jit_enabled');

        $warmup = $this->requireArray($budget, 'warmup_policy');
        $warmHttpWarmups = $this->requireNonNegativeInteger($warmup, 'warm_http_subject_warmups');
        $bootWarmups = $this->requireNonNegativeInteger($warmup, 'application_boot_measured_worker_in_process_warmups');
        $discardedBootWorkers = $this->requirePositiveInteger($warmup, 'application_boot_discarded_worker_processes_per_measured_sample');

        $identity = $this->requireArray($budget, 'comparison_identity');
        $this->requireExactString($identity, 'manifest_schema_version', 'evolvephp.comparator.evidence-manifest.v1');
        $this->requireExactString($identity, 'comparator_result_schema_version', 'evolvephp.comparator.result.v1');
        $this->requireExactString($identity, 'comparator_id', 'evolvephp');

        foreach ([
            'execution_environment_fingerprint',
            'matrix_sha256',
            'comparator_lock_sha256',
            'fixture_identity_hash',
        ] as $key) {
            $this->requireSha($identity, $key, 64);
        }

        $protocol = $this->requireArray($budget, 'sample_protocol');
        $sampleCount = $this->requirePositiveInteger($protocol, 'sample_count');
        $this->requireExactPositiveInteger($calibration, 'sample_count', $sampleCount);
        $warmups = $this->requireNonNegativeInteger($protocol, 'warmups');
        $requestCount = $this->requirePositiveInteger($protocol, 'request_count');
        $repeatedWarmOperations = $this->requirePositiveInteger($protocol, 'repeated_warm_operations_per_sample');
        $this->requireExactString($protocol, 'process_isolation_model', 'subprocess_per_measured_sample');

        if ($warmups !== $warmHttpWarmups) {
            throw new InvalidArgumentException('Invalid performance budget: warmup policy does not match sample protocol.');
        }

        if ($requestCount !== $repeatedWarmOperations) {
            throw new InvalidArgumentException('Invalid performance budget: repeated-warm operation count must match request count.');
        }

        $bootProtocol = $this->requireArray($protocol, 'boot_protocol');
        $this->requireExactPositiveInteger($bootProtocol, 'discarded_worker_processes_per_measured_sample', $discardedBootWorkers);
        $this->requireExactString($bootProtocol, 'sample_order', 'rotating_round_robin');
        $this->requireExactNonNegativeInteger($bootProtocol, 'measured_worker_in_process_warmups', $bootWarmups);
        $this->requireExactString($bootProtocol, 'outlier_policy', 'retain_all_measured_samples');
        $this->requireExactString($bootProtocol, 'primary_central_statistic', 'p50');

        $scenarios = $this->requireArray($budget, 'scenarios');
        if ($scenarios === []) {
            throw new InvalidArgumentException('Invalid performance budget: scenarios must not be empty.');
        }

        $scenarioIds = array_keys($scenarios);
        sort($scenarioIds, SORT_STRING);
        $requiredScenarioIds = self::REQUIRED_SCENARIOS;
        sort($requiredScenarioIds, SORT_STRING);

        if ($scenarioIds !== $requiredScenarioIds) {
            throw new InvalidArgumentException('Invalid performance budget: scenarios must contain exactly the supported scenario set.');
        }

        foreach ($scenarios as $scenarioId => $scenario) {
            $this->assertValidScenarioPolicy((string) $scenarioId, $scenario, (int) $calibration['run_count']);
        }
    }

    /**
     * @param array<string, mixed> $budget
     * @param array<string, mixed> $candidateEvidence
     * @return list<string>
     */
    private function globalIncomparabilityReasons(array $budget, array $candidateEvidence): array
    {
        $manifest = $candidateEvidence['manifest'] ?? null;

        if (!is_array($manifest)) {
            return ['candidate evidence is missing manifest'];
        }

        $identity = $budget['comparison_identity'];
        $protocol = $budget['sample_protocol'];
        $reasons = [];

        $this->appendMismatch(
            $reasons,
            'manifest schema version',
            $identity['manifest_schema_version'],
            $manifest['schema_version'] ?? null,
        );
        $this->appendMismatch($reasons, 'manifest status', 'completed', $manifest['status'] ?? null);

        if (!is_array($manifest['source'] ?? null)) {
            $reasons[] = 'candidate source evidence is missing';
        } elseif (!$this->validSha($manifest['source']['git_sha'] ?? null, 40)) {
            $reasons[] = 'candidate source SHA is missing or invalid';
        }

        if (!is_array($manifest['source'] ?? null) || !array_key_exists('dirty', $manifest['source']) || !is_bool($manifest['source']['dirty']) || $manifest['source']['dirty'] !== false) {
            $reasons[] = 'candidate canonical evidence dirty state is missing, invalid, or dirty';
        }

        $this->appendMismatch(
            $reasons,
            'execution environment fingerprint',
            $identity['execution_environment_fingerprint'],
            $manifest['execution_environment_fingerprint'] ?? null,
        );
        $this->appendMismatch($reasons, 'matrix hash', $identity['matrix_sha256'], $manifest['matrix']['sha256'] ?? null);
        $this->appendMismatch(
            $reasons,
            'process isolation model',
            $protocol['process_isolation_model'],
            $manifest['process_isolation']['model'] ?? null,
        );
        $this->appendMismatch($reasons, 'sample count protocol', $protocol['sample_count'], $manifest['samples'] ?? null);
        $this->appendMismatch($reasons, 'warmup protocol', $protocol['warmups'], $manifest['warmups'] ?? null);
        $this->appendMismatch($reasons, 'request count protocol', $protocol['request_count'], $manifest['request_count'] ?? null);

        if (($manifest['boot_protocol'] ?? null) !== $protocol['boot_protocol']) {
            $reasons[] = 'application boot protocol identity mismatch';
        }

        return $reasons;
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $budget
     * @param array<string, mixed>|null $candidate
     * @return array<string, mixed>
     */
    private function evaluateScenario(
        string $scenarioId,
        array $policy,
        array $budget,
        ?array $candidate,
        ?string $manifestSourceSha,
    ): array {
        if ($candidate === null) {
            return $this->scenarioResult($policy, 'incomparable', null, ['missing required scenario ' . $scenarioId]);
        }

        $reasons = $this->scenarioIncomparabilityReasons($scenarioId, $candidate, $budget, $manifestSourceSha);

        if ($reasons !== []) {
            return $this->scenarioResult($policy, 'incomparable', null, $reasons);
        }

        $scenario = $candidate['baseline_result']['scenarios'][0];
        $p50 = (float) $scenario['p50'];
        $observedMaximum = (float) $policy['observed_maximum_p50_microseconds'];
        $mode = (string) $policy['mode'];

        if ($p50 <= $observedMaximum) {
            return $this->scenarioResult($policy, 'pass', $p50);
        }

        if ($mode === 'blocking' && $p50 > (float) $policy['blocking_threshold_p50_microseconds']) {
            return $this->scenarioResult($policy, 'fail', $p50, ['p50 exceeds blocking threshold']);
        }

        $reasons = ['p50 exceeds observed calibration envelope'];

        if (
            $mode === 'monitor'
            && $p50 > (float) $policy['observation_threshold_p50_microseconds']
        ) {
            $reasons[] = 'p50 exceeds observation boundary; repeat investigation is required';
        }

        return $this->scenarioResult($policy, 'warn', $p50, $reasons);
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $budget
     * @return list<string>
     */
    private function scenarioIncomparabilityReasons(
        string $scenarioId,
        array $candidate,
        array $budget,
        ?string $manifestSourceSha,
    ): array {
        $identity = $budget['comparison_identity'];
        $protocol = $budget['sample_protocol'];
        $reasons = [];

        $this->appendMismatch(
            $reasons,
            'comparator result schema version',
            $identity['comparator_result_schema_version'],
            $candidate['comparator_schema_version'] ?? null,
        );
        $this->appendMismatch($reasons, 'comparator identity', $identity['comparator_id'], $candidate['comparator_id'] ?? null);
        $this->appendMismatch($reasons, 'scenario identity', $scenarioId, $candidate['scenario_id'] ?? null);
        $this->appendMismatch(
            $reasons,
            'execution environment fingerprint',
            $identity['execution_environment_fingerprint'],
            $candidate['execution_environment_fingerprint'] ?? null,
        );
        $this->appendMismatch($reasons, 'matrix hash', $identity['matrix_sha256'], $candidate['matrix_sha256'] ?? null);
        $this->appendMismatch($reasons, 'EvolvePHP comparator lock hash', $identity['comparator_lock_sha256'], $candidate['comparator_lock_sha256'] ?? null);
        $this->appendMismatch($reasons, 'fixture identity hash', $identity['fixture_identity_hash'], $candidate['fixture_identity_hash'] ?? null);

        if (($candidate['availability'] ?? null) !== 'available') {
            $reasons[] = 'comparator result is not available';
        }

        if (!$this->validSha($candidate['source_evolvephp_sha'] ?? null, 40)) {
            $reasons[] = 'candidate source SHA is missing or invalid';
        } elseif ($manifestSourceSha !== null && $candidate['source_evolvephp_sha'] !== $manifestSourceSha) {
            $reasons[] = 'candidate source SHA does not match manifest source SHA';
        }

        if (!array_key_exists('source_dirty', $candidate) || !is_bool($candidate['source_dirty']) || $candidate['source_dirty'] !== false) {
            $reasons[] = 'candidate normalized evidence dirty state is missing, invalid, or dirty';
        }

        $baseline = $candidate['baseline_result'] ?? null;
        if (!is_array($baseline) || !is_array($baseline['scenarios'] ?? null) || !isset($baseline['scenarios'][0]) || !is_array($baseline['scenarios'][0])) {
            $reasons[] = 'malformed or missing normalized result';

            return $reasons;
        }

        if (array_key_exists('source_sha', $baseline) && $baseline['source_sha'] !== $manifestSourceSha) {
            $reasons[] = 'baseline result source SHA does not match manifest source SHA';
        }

        $scenario = $baseline['scenarios'][0];
        $this->appendMismatch($reasons, 'normalized scenario identity', $scenarioId, $scenario['id'] ?? null);
        $this->appendMismatch($reasons, 'normalized timing unit', $this->expectedUnit($scenarioId), $scenario['unit'] ?? null);

        if (!$this->positiveFiniteNumber($scenario['p50'] ?? null)) {
            $reasons[] = 'normalized p50 is missing, non-finite, or not greater than zero';
        }

        if (($scenario['p50_status'] ?? 'available') !== 'available') {
            $reasons[] = 'normalized p50 status is not available';
        }

        if (($scenario['sample_count'] ?? null) !== $protocol['sample_count']) {
            $reasons[] = 'normalized sample count protocol mismatch';
        }

        if (
            $scenarioId === 'http_repeated_warm'
            && ($scenario['operations_per_sample'] ?? null) !== $protocol['repeated_warm_operations_per_sample']
        ) {
            $reasons[] = 'repeated-warm operations per sample protocol mismatch';
        }

        return $reasons;
    }

    /**
     * @param array<string, mixed> $policy
     * @param list<string> $reasons
     * @return array<string, mixed>
     */
    private function scenarioResult(array $policy, string $status, ?float $p50, array $reasons = []): array
    {
        return [
            'status' => $status,
            'blocking' => $status === 'fail',
            'mode' => $policy['mode'],
            'p50_microseconds' => $p50,
            'reference_p50_microseconds' => $policy['reference_p50_microseconds'],
            'observed_maximum_p50_microseconds' => $policy['observed_maximum_p50_microseconds'],
            'threshold_p50_microseconds' => $policy['blocking_threshold_p50_microseconds']
                ?? $policy['observation_threshold_p50_microseconds'],
            'reasons' => $reasons,
        ];
    }

    /**
     * @param mixed $scenario
     */
    private function assertValidScenarioPolicy(string $scenarioId, mixed $scenario, int $calibrationRunCount): void
    {
        if ($scenarioId === '') {
            throw new InvalidArgumentException('Invalid performance budget: scenario ids must be non-empty strings.');
        }

        if (!is_array($scenario)) {
            throw new InvalidArgumentException("Invalid performance budget: scenario {$scenarioId} must be an object.");
        }

        $mode = $this->requireString($scenario, 'mode');
        if ($scenarioId === 'application_boot') {
            $this->requireExactString($scenario, 'mode', 'monitor');
        } else {
            $this->requireExactString($scenario, 'mode', 'blocking');
        }

        $observed = $this->requirePositiveFiniteNumberList($scenario, 'observed_p50_microseconds', $calibrationRunCount);
        $reference = $this->requirePositiveFiniteNumber($scenario, 'reference_p50_microseconds');
        $observedMaximum = $this->requirePositiveFiniteNumber($scenario, 'observed_maximum_p50_microseconds');
        $rangePercent = $this->requireNonNegativeFiniteNumber($scenario, 'cross_run_range_percent');

        if ($reference > $observedMaximum) {
            throw new InvalidArgumentException("Invalid performance budget: scenario {$scenarioId} reference p50 exceeds observed maximum.");
        }

        if (!$this->floatEquals($reference, $this->median($observed))) {
            throw new InvalidArgumentException("Invalid performance budget: scenario {$scenarioId} reference p50 does not match observed median.");
        }

        if (!$this->floatEquals($observedMaximum, max($observed))) {
            throw new InvalidArgumentException("Invalid performance budget: scenario {$scenarioId} observed maximum does not match observed p50 values.");
        }

        if (!$this->floatEquals($rangePercent, $this->rangePercent($observed))) {
            throw new InvalidArgumentException("Invalid performance budget: scenario {$scenarioId} cross-run range does not match observed p50 values.");
        }

        if ($mode === 'blocking') {
            $threshold = $this->requirePositiveFiniteNumber($scenario, 'blocking_threshold_p50_microseconds');
            if ($threshold <= $observedMaximum) {
                throw new InvalidArgumentException("Invalid performance budget: scenario {$scenarioId} blocking threshold must exceed observed maximum.");
            }

            return;
        }

        $threshold = $this->requirePositiveFiniteNumber($scenario, 'observation_threshold_p50_microseconds');
        if ($threshold <= $observedMaximum) {
            throw new InvalidArgumentException("Invalid performance budget: scenario {$scenarioId} observation threshold must exceed observed maximum.");
        }
    }

    /**
     * @param array<string, mixed> $candidateEvidence
     * @return array<string, mixed>|null
     */
    private function candidateResultForScenario(array $candidateEvidence, string $scenarioId): ?array
    {
        if (!is_array($candidateEvidence['results'] ?? null)) {
            return null;
        }

        foreach ($candidateEvidence['results'] as $result) {
            if (is_array($result) && ($result['scenario_id'] ?? null) === $scenarioId) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $statuses
     */
    private function aggregateStatus(array $statuses): string
    {
        foreach (['incomparable', 'fail', 'warn'] as $state) {
            if (in_array($state, $statuses, true)) {
                return $state;
            }
        }

        return 'pass';
    }

    /**
     * @param list<string> $reasons
     */
    private function appendMismatch(array &$reasons, string $label, mixed $expected, mixed $actual): void
    {
        if ($actual !== $expected) {
            $reasons[] = $label . ' mismatch';
        }
    }

    private function expectedUnit(string $scenarioId): string
    {
        return $scenarioId === 'http_repeated_warm' ? 'per_operation_microseconds' : 'microseconds';
    }

    private function manifestSourceSha(mixed $manifest): ?string
    {
        if (!is_array($manifest) || !is_array($manifest['source'] ?? null)) {
            return null;
        }

        $sha = $manifest['source']['git_sha'] ?? null;

        return $this->validSha($sha, 40) ? $sha : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireString(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
            throw new InvalidArgumentException("Invalid performance budget: {$key} is required.");
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireExactString(array $data, string $key, string $expected): string
    {
        $actual = $this->requireString($data, $key);

        if ($actual !== $expected) {
            throw new InvalidArgumentException("Invalid performance budget: {$key} must be {$expected}.");
        }

        return $actual;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function requireArray(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            throw new InvalidArgumentException("Invalid performance budget: {$key} is required.");
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireSha(array $data, string $key, int $length): void
    {
        if (!$this->validSha($data[$key] ?? null, $length)) {
            throw new InvalidArgumentException("Invalid performance budget: {$key} must be a {$length}-character hexadecimal hash.");
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireBoolean(array $data, string $key): bool
    {
        if (!array_key_exists($key, $data) || !is_bool($data[$key])) {
            throw new InvalidArgumentException("Invalid performance budget: {$key} must be boolean.");
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requirePositiveInteger(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key]) || $data[$key] < 1) {
            throw new InvalidArgumentException("Invalid performance budget: {$key} must be a positive integer.");
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireNonNegativeInteger(array $data, string $key): int
    {
        if (!array_key_exists($key, $data) || !is_int($data[$key]) || $data[$key] < 0) {
            throw new InvalidArgumentException("Invalid performance budget: {$key} must be a non-negative integer.");
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireExactPositiveInteger(array $data, string $key, int $expected): int
    {
        $actual = $this->requirePositiveInteger($data, $key);

        if ($actual !== $expected) {
            throw new InvalidArgumentException("Invalid performance budget: {$key} must be {$expected}.");
        }

        return $actual;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireExactNonNegativeInteger(array $data, string $key, int $expected): int
    {
        $actual = $this->requireNonNegativeInteger($data, $key);

        if ($actual !== $expected) {
            throw new InvalidArgumentException("Invalid performance budget: {$key} must be {$expected}.");
        }

        return $actual;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requirePositiveFiniteNumber(array $data, string $key): float
    {
        if (!$this->positiveFiniteNumber($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid performance budget: {$key} must be a positive finite number.");
        }

        return (float) $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireNonNegativeFiniteNumber(array $data, string $key): float
    {
        if (!isset($data[$key]) || !is_numeric($data[$key]) || !is_finite((float) $data[$key]) || (float) $data[$key] < 0.0) {
            throw new InvalidArgumentException("Invalid performance budget: {$key} must be a non-negative finite number.");
        }

        return (float) $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<float>
     */
    private function requirePositiveFiniteNumberList(array $data, string $key, int $expectedCount): array
    {
        if (!isset($data[$key]) || !is_array($data[$key]) || count($data[$key]) !== $expectedCount) {
            throw new InvalidArgumentException("Invalid performance budget: {$key} must contain {$expectedCount} values.");
        }

        $values = [];
        foreach ($data[$key] as $value) {
            if (!$this->positiveFiniteNumber($value)) {
                throw new InvalidArgumentException("Invalid performance budget: {$key} contains a non-positive or non-finite value.");
            }

            $values[] = (float) $value;
        }

        return $values;
    }

    private function positiveFiniteNumber(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0.0;
    }

    /**
     * @param list<float> $values
     */
    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * @param list<float> $values
     */
    private function rangePercent(array $values): float
    {
        $minimum = min($values);

        return ((max($values) - $minimum) / $minimum) * 100;
    }

    private function floatEquals(float $left, float $right): bool
    {
        return abs($left - $right) <= self::FLOAT_TOLERANCE;
    }

    private function validSha(mixed $value, int $length): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{' . $length . '}\z/', $value) === 1;
    }
}
