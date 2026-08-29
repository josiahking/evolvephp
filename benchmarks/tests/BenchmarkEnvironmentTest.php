<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests;

use Evolve\Benchmarks\Support\BenchmarkEnvironment;
use Evolve\Benchmarks\Support\EnvironmentFingerprint;
use PHPUnit\Framework\TestCase;

final class BenchmarkEnvironmentTest extends TestCase
{
    public function testEnvironmentCaptureContainsComparableRuntimeFields(): void
    {
        $environment = BenchmarkEnvironment::capture(dirname(__DIR__, 2));

        foreach ([
            'schema_version',
            'source',
            'runtime',
            'platform',
            'composer',
            'phpbench',
            'opcache',
            'jit',
            'extensions',
            'lock',
            'fingerprint',
        ] as $field) {
            self::assertArrayHasKey($field, $environment);
        }

        self::assertSame('evolvephp.benchmark.environment.v1', $environment['schema_version']);
        self::assertIsString($environment['source']['git_sha']);
        self::assertNotSame('', $environment['source']['git_sha']);
        self::assertIsBool($environment['source']['dirty']);
        self::assertSame(PHP_VERSION, $environment['runtime']['php_version']);
        self::assertSame(PHP_SAPI, $environment['runtime']['php_sapi']);
        self::assertSame($environment['extensions'], array_values(array_unique($environment['extensions'])));
        self::assertSame($environment['extensions'], [...$environment['extensions']]);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $environment['fingerprint']['hash']);
    }

    public function testFingerprintIsStableAndIgnoresRunTimestamp(): void
    {
        $environment = [
            'captured_at' => '2026-01-01T00:00:00+00:00',
            'runtime' => ['php_version' => '8.4.0'],
            'platform' => ['os' => 'WINNT'],
        ];

        $changedTimestamp = $environment;
        $changedTimestamp['captured_at'] = '2026-01-01T00:00:01+00:00';

        self::assertSame(
            EnvironmentFingerprint::fromEnvironment($environment)['hash'],
            EnvironmentFingerprint::fromEnvironment($changedTimestamp)['hash'],
        );
    }
}
