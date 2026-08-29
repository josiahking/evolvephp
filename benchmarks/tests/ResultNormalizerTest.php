<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests;

use Evolve\Benchmarks\Support\ResultNormalizer;
use PHPUnit\Framework\TestCase;

final class ResultNormalizerTest extends TestCase
{
    public function testNormalizerCalculatesSupportedStatisticsWithoutInventingPercentiles(): void
    {
        $normalized = ResultNormalizer::normalize([
            'environment' => [
                'source' => ['git_sha' => 'abc123'],
                'fingerprint' => ['hash' => str_repeat('a', 64)],
            ],
            'scenarios' => [
                [
                    'id' => 'route.static.10.first',
                    'samples' => [100.0, 120.0, 110.0, 130.0],
                    'unit' => 'microseconds',
                    'memory' => ['current_bytes' => 1024, 'peak_bytes' => 2048],
                ],
            ],
        ]);

        self::assertSame('evolvephp.benchmark.results.v1', $normalized['schema_version']);
        self::assertSame('abc123', $normalized['source_sha']);
        self::assertSame(str_repeat('a', 64), $normalized['environment_fingerprint']);
        self::assertSame('route.static.10.first', $normalized['scenarios'][0]['id']);
        self::assertSame(4, $normalized['scenarios'][0]['sample_count']);
        self::assertSame(115.0, $normalized['scenarios'][0]['mean']);
        self::assertSame(100.0, $normalized['scenarios'][0]['min']);
        self::assertSame(130.0, $normalized['scenarios'][0]['max']);
        self::assertSame(115.0, $normalized['scenarios'][0]['p50']);
        self::assertNull($normalized['scenarios'][0]['p95']);
        self::assertNull($normalized['scenarios'][0]['p99']);
        self::assertSame('insufficient_samples', $normalized['scenarios'][0]['p95_status']);
    }

    public function testNormalizerKeepsHighPercentilesWhenEnoughSamplesExist(): void
    {
        $samples = range(1, 100);
        $normalized = ResultNormalizer::normalize([
            'environment' => [
                'source' => ['git_sha' => 'abc123'],
                'fingerprint' => ['hash' => str_repeat('b', 64)],
            ],
            'scenarios' => [
                [
                    'id' => 'execution.no-sink',
                    'samples' => $samples,
                    'unit' => 'microseconds',
                ],
            ],
        ]);

        self::assertSame(50.5, $normalized['scenarios'][0]['p50']);
        self::assertSame(95.0, $normalized['scenarios'][0]['p95']);
        self::assertSame(99.0, $normalized['scenarios'][0]['p99']);
        self::assertSame('available', $normalized['scenarios'][0]['p99_status']);
    }
}
