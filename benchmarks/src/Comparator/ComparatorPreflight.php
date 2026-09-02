<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Comparator;

use Evolve\Benchmarks\Support\BenchmarkEnvironment;
use Evolve\Benchmarks\Support\ExecutionEnvironmentFingerprint;

final class ComparatorPreflight
{
    public const SCHEMA_VERSION = 'evolvephp.comparator.preflight.v1';

    /**
     * @return array<string, mixed>
     */
    public static function current(string $repositoryRoot, string $matrixPath): array
    {
        return self::fromEnvironment(BenchmarkEnvironment::capture($repositoryRoot), $matrixPath, self::canonicalRequirements());
    }

    /**
     * @param array<string, mixed> $environment
     * @param array<string, mixed> $requirements
     * @return array<string, mixed>
     */
    public static function fromEnvironment(array $environment, string $matrixPath, array $requirements): array
    {
        $runtimeIdentity = ComparatorRuntimeIdentity::fromCapturedEnvironment($environment);
        $executionEnvironmentIdentity = ExecutionEnvironmentFingerprint::fromEnvironment($environment);
        $mismatches = [];

        if (($runtimeIdentity['runtime']['php_version'] ?? null) !== $requirements['php_version']) {
            $mismatches[] = 'PHP version is not exactly ' . $requirements['php_version'] . '.';
        }

        if (($runtimeIdentity['opcache']['enabled'] ?? null) !== $requirements['opcache_cli_enabled']) {
            $mismatches[] = 'OPcache is not enabled for CLI.';
        }

        if (($runtimeIdentity['jit']['enabled'] ?? null) !== $requirements['jit_enabled']) {
            $mismatches[] = 'JIT is not disabled.';
        }

        if (($runtimeIdentity['phalcon']['version'] ?? null) !== $requirements['phalcon_extension_version']) {
            $mismatches[] = 'ext-phalcon ' . $requirements['phalcon_extension_version'] . ' is not loaded.';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $mismatches === [] ? 'matched' : 'mismatched',
            'canonical_requirements' => $requirements,
            'mismatches' => $mismatches,
            'environment' => $environment,
            'execution_environment_identity' => $executionEnvironmentIdentity,
            'execution_environment_fingerprint' => $executionEnvironmentIdentity['hash'],
            'worker_environment_identity' => $runtimeIdentity,
            'matrix' => [
                'path' => 'benchmarks/comparators/matrix.json',
                'sha256' => is_file($matrixPath) ? hash_file('sha256', $matrixPath) : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function canonicalRequirements(): array
    {
        return [
            'php_version' => '8.4.25',
            'opcache_cli_enabled' => true,
            'jit_enabled' => false,
            'phalcon_extension_version' => '5.20.3',
            'same_extension_lane_for_all_comparator_processes' => true,
        ];
    }
}
