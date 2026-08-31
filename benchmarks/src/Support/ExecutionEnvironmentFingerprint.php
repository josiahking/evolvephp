<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Support;

/**
 * ExecutionEnvironmentFingerprint represents the material execution conditions.
 *
 * This fingerprint EXCLUDES lock_hash because the same hardware/PHP/OPcache/JIT
 * configuration should be comparable across different framework fixtures.
 *
 * Fields included: schema_version, runtime, platform, composer_version, phpbench_version,
 * opcache, jit, extensions
 */
final class ExecutionEnvironmentFingerprint
{
    public const SCHEMA_VERSION = 'evolvephp.benchmark.environment.v2';

    /**
     * @param array<string, mixed> $environment
     * @return array{hash: string, fields: array<string, mixed>}
     */
    public static function fromEnvironment(array $environment): array
    {
        // Extract only execution-environment relevant fields
        $fields = [
            'schema_version' => self::SCHEMA_VERSION,
            'runtime' => $environment['runtime'] ?? [],
            'platform' => $environment['platform'] ?? [],
            'composer_version' => $environment['composer']['version'] ?? null,
            'phpbench_version' => $environment['phpbench']['version'] ?? null,
            'opcache' => $environment['opcache'] ?? [],
            'jit' => $environment['jit'] ?? [],
            'extensions' => $environment['extensions'] ?? [],
            // NOTE: lock_hash is intentionally excluded
        ];

        self::sortRecursive($fields);

        return [
            'hash' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'fields' => $fields,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function sortRecursive(array &$value): void
    {
        foreach ($value as &$entry) {
            if (is_array($entry)) {
                self::sortRecursive($entry);
            }
        }

        if (!array_is_list($value)) {
            ksort($value);
        }
    }
}
