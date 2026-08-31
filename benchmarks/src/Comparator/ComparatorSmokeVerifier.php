<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Comparator;

use Throwable;

final class ComparatorSmokeVerifier
{
    public const SCHEMA_VERSION = 'evolvephp.comparator.smoke.v1';

    /**
     * @return array<string, mixed>
     */
    public static function verifyMatrixFile(string $matrixPath): array
    {
        $matrix = ComparatorMatrix::fromJsonFile($matrixPath);
        $baseDir = dirname($matrixPath);
        $comparators = [];
        $overallStatus = 'passed';

        foreach ($matrix->comparators() as $id => $definition) {
            $result = self::verifyComparator($baseDir, $definition);
            $comparators[] = $result;

            if (($result['status'] ?? 'failed') === 'failed') {
                $overallStatus = 'failed';
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $overallStatus,
            'comparator_count' => count($comparators),
            'comparators' => $comparators,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private static function verifyComparator(string $baseDir, array $definition): array
    {
        $id = (string) $definition['id'];

        try {
            $fixture = self::loadFixture($baseDir, $definition);
            $availability = $fixture->availability();

            if (($availability['available'] ?? false) !== true) {
                return [
                    'id' => $id,
                    'framework_version' => $definition['framework_version'],
                    'composer_constraint' => $definition['composer_constraint'],
                    'availability' => 'unavailable',
                    'status' => 'skipped',
                    'reason' => (string) ($availability['reason'] ?? 'comparator unavailable'),
                ];
            }

            $scenarios = [
                'application_boot' => $fixture->applicationBoot(),
                'http_static' => $fixture->httpStatic(),
                'http_parameterized' => $fixture->httpParameterized('123'),
                'http_middleware' => $fixture->httpMiddleware(),
                'http_not_found' => $fixture->httpNotFound(),
                'http_repeated_warm' => $fixture->httpRepeatedWarm(3),
            ];

            self::assertScenarioResults($id, $scenarios);

            return [
                'id' => $id,
                'framework_version' => $definition['framework_version'],
                'composer_constraint' => $definition['composer_constraint'],
                'availability' => 'available',
                'status' => 'passed',
                'scenarios' => $scenarios,
            ];
        } catch (Throwable $exception) {
            return [
                'id' => $id,
                'framework_version' => $definition['framework_version'] ?? null,
                'composer_constraint' => $definition['composer_constraint'] ?? null,
                'availability' => 'available',
                'status' => 'failed',
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function loadFixture(string $baseDir, array $definition): ComparatorFixture
    {
        $path = $baseDir . DIRECTORY_SEPARATOR . trim((string) $definition['fixture_bootstrap'], '/\\');
        $fixture = require $path;

        if (!$fixture instanceof ComparatorFixture) {
            throw new ComparatorMatrixException("Comparator '{$definition['id']}' bootstrap must return a ComparatorFixture");
        }

        return $fixture;
    }

    /**
     * @param array<string, array<string, mixed>> $scenarios
     */
    private static function assertScenarioResults(string $id, array $scenarios): void
    {
        self::assertSame('ok', $scenarios['application_boot']['status'] ?? null, "{$id} application_boot failed");
        self::assertSame(200, $scenarios['http_static']['status_code'] ?? null, "{$id} static route failed");
        self::assertSame("{$id}:static", $scenarios['http_static']['body'] ?? null, "{$id} static body mismatch");

        self::assertSame(200, $scenarios['http_parameterized']['status_code'] ?? null, "{$id} parameterized route failed");
        self::assertSame(['id' => '123'], $scenarios['http_parameterized']['parameters'] ?? null, "{$id} parameter capture failed");
        self::assertSame("{$id}:parameterized:123", $scenarios['http_parameterized']['body'] ?? null, "{$id} parameterized body mismatch");

        self::assertSame(200, $scenarios['http_middleware']['status_code'] ?? null, "{$id} middleware route failed");
        self::assertSame([1, 2, 3, 4, 5], $scenarios['http_middleware']['middleware_order'] ?? null, "{$id} middleware order mismatch");

        self::assertSame(404, $scenarios['http_not_found']['status_code'] ?? null, "{$id} not-found path failed");
        self::assertSame(true, $scenarios['http_not_found']['not_found'] ?? null, "{$id} not-found marker missing");

        self::assertSame(200, $scenarios['http_repeated_warm']['status_code'] ?? null, "{$id} repeated warm route failed");
        self::assertSame(3, $scenarios['http_repeated_warm']['request_count'] ?? null, "{$id} repeated warm request count mismatch");
        self::assertSame(1, $scenarios['http_repeated_warm']['bootstrap_count'] ?? null, "{$id} repeated warm rebuilt the fixture");
    }

    private static function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new ComparatorMatrixException($message);
        }
    }
}
