<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests\Comparator;

use Evolve\Benchmarks\Comparator\ComparatorResultNormalizer;
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
}
