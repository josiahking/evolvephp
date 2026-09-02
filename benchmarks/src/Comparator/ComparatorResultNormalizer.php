<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Comparator;

/**
 * ComparatorResultNormalizer extends result normalization for cross-framework comparisons.
 *
 * The comparator result schema adds:
 * - comparator_id (which framework)
 * - framework_name (display name)
 * - framework_version (exact version)
 * - fixture_identity (hash of fixture configuration)
 * - execution_environment_fingerprint (hash of runtime conditions, excluding lockfile)
 * - availability status
 *
 * This complements the existing evolvephp.benchmark.results.v1 schema without breaking it.
 * EvolvePHP baseline results use v1; comparator results include additional v2 metadata.
 */
final class ComparatorResultNormalizer
{
    public const SCHEMA_VERSION = 'evolvephp.comparator.result.v1';

    /**
     * Add comparator metadata to a normalized result.
     *
     * @param array<string, mixed> $normalizedResult (from ResultNormalizer::normalize)
     * @param array<string, mixed> $comparatorMetadata
     * @return array<string, mixed>
     */
    public static function withComparatorMetadata(array $normalizedResult, array $comparatorMetadata): array
    {
        return [
            'comparator_schema_version' => self::SCHEMA_VERSION,
            'comparator_id' => $comparatorMetadata['id'] ?? null,
            'framework_name' => $comparatorMetadata['name'] ?? null,
            'framework_version' => $comparatorMetadata['framework_version'] ?? $comparatorMetadata['version'] ?? null,
            'fixture_identity' => $comparatorMetadata['fixture_identity'] ?? null,
            'fixture_identity_hash' => $comparatorMetadata['fixture_identity_hash'] ?? ($comparatorMetadata['fixture_identity']['hash'] ?? null),
            'execution_environment_identity' => $comparatorMetadata['execution_environment_identity'] ?? null,
            'execution_environment_fingerprint' => $comparatorMetadata['execution_environment_fingerprint'] ?? ($comparatorMetadata['execution_environment_identity']['hash'] ?? null),
            'worker_environment_identity' => $comparatorMetadata['worker_environment_identity'] ?? null,
            'source_evolvephp_sha' => $comparatorMetadata['source_evolvephp_sha'] ?? ($normalizedResult['source_sha'] ?? null),
            'source_dirty' => $comparatorMetadata['source_dirty'] ?? null,
            'matrix_sha256' => $comparatorMetadata['matrix_sha256'] ?? null,
            'comparator_lock_sha256' => $comparatorMetadata['comparator_lock_sha256'] ?? null,
            'raw_evidence_sha256' => $comparatorMetadata['raw_evidence_sha256'] ?? null,
            'scenario_id' => $comparatorMetadata['scenario_id'] ?? null,
            'availability' => $comparatorMetadata['availability'] ?? 'available',
            'availability_status' => $comparatorMetadata['availability_status'] ?? null,
            'implementation_model' => $comparatorMetadata['implementation_model'] ?? 'pure-php',
            'notes' => $comparatorMetadata['notes'] ?? [],
            'baseline_result' => $normalizedResult,
        ];
    }

    /**
     * Create result for an unavailable comparator.
     *
     * @param array<string, mixed> $comparatorMetadata
     * @return array<string, mixed>
     */
    public static function unavailable(array $comparatorMetadata): array
    {
        return [
            'comparator_schema_version' => self::SCHEMA_VERSION,
            'comparator_id' => $comparatorMetadata['id'] ?? null,
            'framework_name' => $comparatorMetadata['name'] ?? null,
            'framework_version' => $comparatorMetadata['framework_version'] ?? $comparatorMetadata['version'] ?? null,
            'fixture_identity' => $comparatorMetadata['fixture_identity'] ?? null,
            'fixture_identity_hash' => $comparatorMetadata['fixture_identity_hash'] ?? ($comparatorMetadata['fixture_identity']['hash'] ?? null),
            'execution_environment_identity' => $comparatorMetadata['execution_environment_identity'] ?? null,
            'execution_environment_fingerprint' => $comparatorMetadata['execution_environment_fingerprint'] ?? ($comparatorMetadata['execution_environment_identity']['hash'] ?? null),
            'worker_environment_identity' => $comparatorMetadata['worker_environment_identity'] ?? null,
            'source_evolvephp_sha' => $comparatorMetadata['source_evolvephp_sha'] ?? null,
            'source_dirty' => $comparatorMetadata['source_dirty'] ?? null,
            'scenario_id' => $comparatorMetadata['scenario_id'] ?? null,
            'implementation_model' => $comparatorMetadata['implementation_model'] ?? null,
            'matrix_sha256' => $comparatorMetadata['matrix_sha256'] ?? null,
            'comparator_lock_sha256' => $comparatorMetadata['comparator_lock_sha256'] ?? null,
            'raw_evidence_sha256' => $comparatorMetadata['raw_evidence_sha256'] ?? null,
            'availability' => 'unavailable',
            'availability_status' => $comparatorMetadata['availability_status'] ?? 'unknown',
            'availability_reason' => $comparatorMetadata['availability_reason'] ?? null,
            'notes' => $comparatorMetadata['notes'] ?? [],
            'baseline_result' => null,
        ];
    }

    /**
     * Create result for an available comparator that failed before producing accepted timing.
     *
     * @param array<string, mixed> $comparatorMetadata
     * @return array<string, mixed>
     */
    public static function failed(array $comparatorMetadata): array
    {
        return [
            'comparator_schema_version' => self::SCHEMA_VERSION,
            'comparator_id' => $comparatorMetadata['id'] ?? null,
            'framework_name' => $comparatorMetadata['name'] ?? null,
            'framework_version' => $comparatorMetadata['framework_version'] ?? $comparatorMetadata['version'] ?? null,
            'fixture_identity' => $comparatorMetadata['fixture_identity'] ?? null,
            'fixture_identity_hash' => $comparatorMetadata['fixture_identity_hash'] ?? ($comparatorMetadata['fixture_identity']['hash'] ?? null),
            'execution_environment_identity' => $comparatorMetadata['execution_environment_identity'] ?? null,
            'execution_environment_fingerprint' => $comparatorMetadata['execution_environment_fingerprint'] ?? ($comparatorMetadata['execution_environment_identity']['hash'] ?? null),
            'worker_environment_identity' => $comparatorMetadata['worker_environment_identity'] ?? null,
            'source_evolvephp_sha' => $comparatorMetadata['source_evolvephp_sha'] ?? null,
            'source_dirty' => $comparatorMetadata['source_dirty'] ?? null,
            'scenario_id' => $comparatorMetadata['scenario_id'] ?? null,
            'implementation_model' => $comparatorMetadata['implementation_model'] ?? null,
            'matrix_sha256' => $comparatorMetadata['matrix_sha256'] ?? null,
            'comparator_lock_sha256' => $comparatorMetadata['comparator_lock_sha256'] ?? null,
            'raw_evidence_sha256' => $comparatorMetadata['raw_evidence_sha256'] ?? null,
            'availability' => 'failed',
            'availability_status' => 'failed',
            'failure_reason' => $comparatorMetadata['failure_reason'] ?? $comparatorMetadata['availability_reason'] ?? null,
            'notes' => $comparatorMetadata['notes'] ?? [],
            'baseline_result' => null,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $environment
     * @return array<string, mixed>
     */
    public static function normalizeRawScenario(array $raw, array $environment): array
    {
        $operationsPerSample = max(1, (int) ($raw['operations_per_sample'] ?? 1));
        $rawSampleRecords = self::rawSampleRecords($raw);
        $samples = array_map(
            static fn(float|int|string $sample): float => ((float) $sample) / $operationsPerSample,
            $rawSampleRecords !== [] ? $rawSampleRecords : (is_array($raw['samples'] ?? null) ? $raw['samples'] : []),
        );
        $unit = $operationsPerSample > 1 ? 'per_operation_microseconds' : (string) ($raw['unit'] ?? 'microseconds');
        $result = \Evolve\Benchmarks\Support\ResultNormalizer::normalize([
            'environment' => $environment,
            'scenarios' => [
                [
                    'id' => (string) ($raw['scenario_id'] ?? 'unknown'),
                    'samples' => $samples,
                    'unit' => $unit,
                    'memory' => is_array($raw['memory'] ?? null) ? $raw['memory'] : [],
                ],
            ],
        ]);

        if (isset($result['scenarios'][0]) && is_array($result['scenarios'][0])) {
            $result['scenarios'][0]['operations_per_sample'] = $operationsPerSample;
            $result['scenarios'][0]['raw_sample_unit'] = (string) ($raw['unit'] ?? 'microseconds');
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<float|int|string>
     */
    private static function rawSampleRecords(array $raw): array
    {
        if (!is_array($raw['raw_samples'] ?? null)) {
            return [];
        }

        $samples = [];

        foreach ($raw['raw_samples'] as $rawSample) {
            if (!is_array($rawSample) || !is_array($rawSample['record'] ?? null)) {
                continue;
            }

            $recordSamples = $rawSample['record']['samples'] ?? null;

            if (!is_array($recordSamples) || !array_key_exists(0, $recordSamples)) {
                continue;
            }

            $samples[] = $recordSamples[0];
        }

        return $samples;
    }
}
