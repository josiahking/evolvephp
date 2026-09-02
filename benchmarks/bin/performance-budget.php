<?php

declare(strict_types=1);

use Evolve\Benchmarks\Support\PerformanceBudgetEvaluator;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$options = getopt('', [
    'budget:',
    'candidate:',
    'validate-reference',
]);

if (!isset($options['budget']) || !is_string($options['budget'])) {
    fwrite(STDERR, "Missing required --budget option.\n");
    exit(2);
}

$budgetPath = $options['budget'];
$evaluator = new PerformanceBudgetEvaluator();

try {
    $budget = readJsonObject($budgetPath);

    if (array_key_exists('validate-reference', $options)) {
        $evaluator->assertValidBudget($budget);
        validateReferenceSummary($budget);

        echo json_encode([
            'schema_version' => PerformanceBudgetEvaluator::EVALUATION_SCHEMA_VERSION,
            'status' => 'pass',
            'validation' => 'reference policy is valid',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }

    if (!isset($options['candidate']) || !is_string($options['candidate'])) {
        fwrite(STDERR, "Missing required --candidate option unless --validate-reference is used.\n");
        exit(2);
    }

    $evaluation = $evaluator->evaluate($budget, readCandidateEvidence($options['candidate']));

    echo json_encode($evaluation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

    exit(match ($evaluation['status']) {
        'pass', 'warn' => 0,
        'fail' => 1,
        default => 2,
    });
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(2);
}

/**
 * @return array<string, mixed>
 */
function readJsonObject(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("JSON file not found: {$path}");
    }

    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new RuntimeException("JSON file must decode to an object: {$path}");
    }

    return $decoded;
}

/**
 * @return array{manifest: array<string, mixed>, results: list<array<string, mixed>>}
 */
function readCandidateEvidence(string $candidateDir): array
{
    if (!is_dir($candidateDir)) {
        throw new RuntimeException("Candidate directory not found: {$candidateDir}");
    }

    $candidateDir = realpath($candidateDir);
    if ($candidateDir === false) {
        throw new RuntimeException('Candidate directory could not be resolved.');
    }

    $manifest = readJsonObject($candidateDir . DIRECTORY_SEPARATOR . 'manifest.json');
    $results = [];

    foreach ($manifest['results'] ?? [] as $result) {
        if (!is_array($result) || !isset($result['normalized_result']['path']) || !is_string($result['normalized_result']['path'])) {
            continue;
        }

        $hash = $result['normalized_result']['sha256'] ?? null;
        if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
            throw new RuntimeException('Manifest normalized_result.sha256 must be a 64-character lowercase hexadecimal hash.');
        }

        $normalizedPath = resolveCandidateFile($candidateDir, $result['normalized_result']['path']);
        $actualHash = hash_file('sha256', $normalizedPath);

        if ($actualHash !== $hash) {
            throw new RuntimeException('Normalized result hash does not match manifest evidence.');
        }

        $results[] = readJsonObject($normalizedPath);
    }

    return [
        'manifest' => $manifest,
        'results' => $results,
    ];
}

function resolveCandidateFile(string $candidateDir, string $relativePath): string
{
    if (preg_match('/\A(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $relativePath) === 1) {
        throw new RuntimeException('Normalized result path must be relative to the candidate directory.');
    }

    $path = realpath($candidateDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));

    if ($path === false || !is_file($path)) {
        throw new RuntimeException('Normalized result file does not exist.');
    }

    $root = str_replace('\\', '/', rtrim($candidateDir, DIRECTORY_SEPARATOR));
    $resolved = str_replace('\\', '/', $path);

    if (!str_starts_with($resolved, $root . '/')) {
        throw new RuntimeException('Normalized result path escapes the candidate directory.');
    }

    return $path;
}

/**
 * @param array<string, mixed> $budget
 */
function validateReferenceSummary(array $budget): void
{
    $referencePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'results' . DIRECTORY_SEPARATOR . 'reference' . DIRECTORY_SEPARATOR . 'performance-summary.json';
    $summary = readJsonObject($referencePath);

    if (($summary['schema_version'] ?? null) !== 'evolvephp.performance-reference-summary.v1') {
        throw new RuntimeException('Reference summary schema_version is not supported.');
    }

    foreach ([
        'regression_baseline_source_sha' => $budget['baseline_source_sha'],
        'canonical_environment_fingerprint' => $budget['comparison_identity']['execution_environment_fingerprint'],
    ] as $field => $expected) {
        if (($summary[$field] ?? null) !== $expected) {
            throw new RuntimeException("Reference summary {$field} does not match the performance budget.");
        }
    }

    $protocol = is_array($summary['calibration_protocol'] ?? null) ? $summary['calibration_protocol'] : [];
    foreach ([
        'run_count' => $budget['calibration']['run_count'],
        'sample_count' => $budget['calibration']['sample_count'],
        'php_version' => $budget['canonical_runtime_policy']['php_version'],
        'opcache_cli_enabled' => $budget['canonical_runtime_policy']['opcache_cli_enabled'],
        'jit_enabled' => $budget['canonical_runtime_policy']['jit_enabled'],
        'primary_metric' => $budget['primary_metric'],
        'repeated_warm_request_count' => $budget['sample_protocol']['request_count'],
    ] as $field => $expected) {
        if (($protocol[$field] ?? null) !== $expected) {
            throw new RuntimeException("Reference summary calibration_protocol.{$field} does not match the performance budget.");
        }
    }

    foreach ($budget['scenarios'] as $scenarioId => $policy) {
        if (!isset($summary['scenarios'][$scenarioId]) || !is_array($summary['scenarios'][$scenarioId])) {
            throw new RuntimeException("Reference summary is missing scenario {$scenarioId}.");
        }

        foreach ([
            'p50_microseconds_by_run' => $policy['observed_p50_microseconds'],
            'reference_median_p50_microseconds' => $policy['reference_p50_microseconds'],
            'observed_maximum_p50_microseconds' => $policy['observed_maximum_p50_microseconds'],
            'observed_range_percent' => $policy['cross_run_range_percent'],
            'budget_classification' => $policy['mode'],
        ] as $field => $expected) {
            if (($summary['scenarios'][$scenarioId][$field] ?? null) !== $expected) {
                throw new RuntimeException("Reference summary {$scenarioId}.{$field} does not match the performance budget.");
            }
        }

        if (($policy['mode'] ?? null) === 'blocking') {
            if (($summary['scenarios'][$scenarioId]['blocking_threshold_p50_microseconds'] ?? null) !== $policy['blocking_threshold_p50_microseconds']) {
                throw new RuntimeException("Reference summary {$scenarioId}.blocking_threshold_p50_microseconds does not match the performance budget.");
            }
        } elseif (($summary['scenarios'][$scenarioId]['observation_threshold_p50_microseconds'] ?? null) !== $policy['observation_threshold_p50_microseconds']) {
            throw new RuntimeException("Reference summary {$scenarioId}.observation_threshold_p50_microseconds does not match the performance budget.");
        }

        if (
            $scenarioId === 'application_boot'
            && (($summary['scenarios'][$scenarioId]['diagnostic_rsd_percent_by_run'] ?? null) !== ($policy['diagnostic_rsd_percent'] ?? null))
        ) {
            throw new RuntimeException('Reference summary application_boot.diagnostic_rsd_percent_by_run does not match the performance budget.');
        }

        if (
            $scenarioId === 'http_repeated_warm'
            && (($summary['scenarios'][$scenarioId]['operations_per_sample'] ?? null) !== $budget['sample_protocol']['repeated_warm_operations_per_sample'])
        ) {
            throw new RuntimeException('Reference summary http_repeated_warm.operations_per_sample does not match the performance budget.');
        }
    }
}
