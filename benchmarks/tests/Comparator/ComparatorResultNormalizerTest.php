<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests\Comparator;

use Evolve\Benchmarks\Comparator\ComparatorResultNormalizer;
use Evolve\Benchmarks\Comparator\ComparatorRuntimeIdentity;
use PHPUnit\Framework\TestCase;

final class ComparatorResultNormalizerTest extends TestCase
{
    public function testComparatorMetadataIncludesFixtureEnvironmentSourceAndScenarioIdentity(): void
    {
        $result = ComparatorResultNormalizer::withComparatorMetadata(
            [
                'schema_version' => 'evolvephp.benchmark.results.v1',
                'source_sha' => str_repeat('a', 40),
                'scenarios' => [
                    [
                        'id' => 'http_static',
                        'sample_count' => 2,
                        'mean' => 10.5,
                    ],
                ],
            ],
            [
                'id' => 'laravel',
                'name' => 'Laravel',
                'framework_version' => '12.68.0',
                'fixture_identity' => ['hash' => str_repeat('b', 64)],
                'execution_environment_identity' => ['hash' => str_repeat('c', 64)],
                'source_evolvephp_sha' => str_repeat('d', 40),
                'scenario_id' => 'http_static',
                'availability' => 'available',
                'notes' => ['local correctness smoke only'],
            ],
        );

        self::assertSame('evolvephp.comparator.result.v1', $result['comparator_schema_version']);
        self::assertSame('laravel', $result['comparator_id']);
        self::assertSame('Laravel', $result['framework_name']);
        self::assertSame('12.68.0', $result['framework_version']);
        self::assertSame(str_repeat('b', 64), $result['fixture_identity']['hash']);
        self::assertSame(str_repeat('c', 64), $result['execution_environment_identity']['hash']);
        self::assertSame(str_repeat('d', 40), $result['source_evolvephp_sha']);
        self::assertSame('http_static', $result['scenario_id']);
        self::assertSame('available', $result['availability']);
        self::assertSame(['local correctness smoke only'], $result['notes']);
        self::assertSame('evolvephp.benchmark.results.v1', $result['baseline_result']['schema_version']);
    }

    public function testUnavailableComparatorResultDoesNotInventTimingFields(): void
    {
        $result = ComparatorResultNormalizer::unavailable([
            'id' => 'phalcon',
            'name' => 'Phalcon',
            'framework_version' => '5.9.3',
            'availability_reason' => 'phalcon extension not loaded',
        ]);

        self::assertSame('unavailable', $result['availability']);
        self::assertSame('phalcon extension not loaded', $result['availability_reason']);
        self::assertNull($result['baseline_result']);
        self::assertArrayNotHasKey('p95', $result);
        self::assertArrayNotHasKey('p99', $result);
        self::assertArrayNotHasKey('timing', $result);
    }

    public function testFailedComparatorResultDoesNotBecomeUnavailable(): void
    {
        $result = ComparatorResultNormalizer::failed([
            'id' => 'evolvephp',
            'name' => 'EvolvePHP',
            'framework_version' => '2.0.x-dev',
            'failure_reason' => 'fixture exploded',
        ]);

        self::assertSame('failed', $result['availability']);
        self::assertSame('fixture exploded', $result['failure_reason']);
        self::assertNull($result['baseline_result']);
        self::assertArrayNotHasKey('timing', $result);
    }

    public function testRepeatedWarmBatchSamplesNormalizeToPerRequestLatency(): void
    {
        $normalized = ComparatorResultNormalizer::normalizeRawScenario([
            'scenario_id' => 'http_repeated_warm',
            'samples' => [1000.0, 2000.0],
            'operations_per_sample' => 10,
            'unit' => 'microseconds',
            'memory' => [],
        ], [
            'source' => ['git_sha' => str_repeat('a', 40)],
            'fingerprint' => ['hash' => str_repeat('b', 64)],
        ]);

        $scenario = $normalized['scenarios'][0];

        self::assertSame('http_repeated_warm', $scenario['id']);
        self::assertSame(10, $scenario['operations_per_sample']);
        self::assertSame('per_operation_microseconds', $scenario['unit']);
        self::assertSame(150.0, $scenario['mean']);
        self::assertSame(1_000_000 / 150.0, $scenario['throughput_per_second']);
    }

    public function testRawSamplesAreTheAcceptedSourceForNormalization(): void
    {
        $normalized = ComparatorResultNormalizer::normalizeRawScenario([
            'scenario_id' => 'http_repeated_warm',
            'samples' => [999_999.0],
            'raw_samples' => [
                [
                    'sample_index' => 1,
                    'sha256' => str_repeat('a', 64),
                    'record' => ['sample_index' => 1, 'samples' => [1000.0]],
                ],
                [
                    'sample_index' => 2,
                    'sha256' => str_repeat('b', 64),
                    'record' => ['sample_index' => 2, 'samples' => [2000.0]],
                ],
            ],
            'operations_per_sample' => 10,
            'unit' => 'microseconds',
            'memory' => [],
        ], [
            'source' => ['git_sha' => str_repeat('a', 40)],
            'fingerprint' => ['hash' => str_repeat('b', 64)],
        ]);

        $scenario = $normalized['scenarios'][0];

        self::assertSame(2, $scenario['sample_count']);
        self::assertSame(150.0, $scenario['mean']);
    }

    public function testRuntimeIdentityConversionUsesOnlySuppliedCapturedEnvironment(): void
    {
        $capturedEnvironment = [
            'runtime' => [
                'php_version' => '8.4.25',
                'php_binary' => 'php',
                'php_sapi' => 'cli',
                'php_ini_loaded_file' => '/tmp/php.ini',
            ],
            'opcache' => ['enabled' => true],
            'jit' => ['enabled' => false],
            'extensions' => ['json', 'phalcon'],
            'extension_versions' => [
                'phalcon' => '5.20.3',
            ],
        ];

        $first = ComparatorRuntimeIdentity::fromCapturedEnvironment($capturedEnvironment);
        $second = ComparatorRuntimeIdentity::fromCapturedEnvironment($capturedEnvironment);

        self::assertSame($first, $second);
        self::assertSame('5.20.3', $first['phalcon']['version']);
    }
}
