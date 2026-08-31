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
            'source_evolvephp_sha' => $comparatorMetadata['source_evolvephp_sha'] ?? ($normalizedResult['source_sha'] ?? null),
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
            'availability' => 'unavailable',
            'availability_status' => $comparatorMetadata['availability_status'] ?? 'unknown',
            'availability_reason' => $comparatorMetadata['availability_reason'] ?? null,
            'notes' => $comparatorMetadata['notes'] ?? [],
            'baseline_result' => null,
        ];
    }
}
