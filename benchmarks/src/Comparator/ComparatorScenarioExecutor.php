<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Comparator;

use RuntimeException;

final class ComparatorScenarioExecutor
{
    public const SCHEMA_VERSION = 'evolvephp.comparator.raw-result.v1';

    /**
     * @return array<string, mixed>
     */
    public static function run(
        string $matrixPath,
        string $comparatorId,
        string $scenarioId,
        int $warmups,
        int $requestCount,
        int $sampleIndex = 1,
    ): array {
        $workerEnvironment = ComparatorRuntimeIdentity::capture();
        $matrix = ComparatorMatrix::fromJsonFile($matrixPath);
        $definition = $matrix->comparator($comparatorId);

        if ($definition === null) {
            throw new RuntimeException("Unknown comparator '{$comparatorId}'.");
        }

        if (!in_array($scenarioId, $definition['scenarios'], true)) {
            throw new RuntimeException("Comparator '{$comparatorId}' does not define scenario '{$scenarioId}'.");
        }

        $fixture = self::loadFixture(dirname($matrixPath), $definition);
        $availability = $fixture->availability();
        $dependencyContext = self::dependencyContext(dirname($matrixPath), $definition);

        if (($availability['available'] ?? false) !== true) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'comparator_id' => $comparatorId,
                'scenario_id' => $scenarioId,
                'availability' => 'unavailable',
                'availability_status' => (string) ($availability['status'] ?? 'unavailable'),
                'reason' => (string) ($availability['reason'] ?? 'comparator unavailable'),
                'sample_count' => 0,
                'samples' => [],
                'unit' => 'microseconds',
                'worker_environment_identity' => $workerEnvironment,
                'dependency_context' => $dependencyContext,
                'process' => self::processMetadata(),
            ];
        }

        if (!$fixture instanceof PreparedComparatorFixture) {
            throw new RuntimeException("Comparator '{$comparatorId}' must implement PreparedComparatorFixture for controlled execution.");
        }

        $warmups = $scenarioId === 'application_boot' ? 0 : max(0, $warmups);
        $requestCount = max(1, $requestCount);
        $subject = $fixture->prepareScenario($scenarioId, [
            'id' => '123',
            'request_count' => $requestCount,
        ]);
        $subjectMetadata = $subject->metadata();
        $dependencyContext = self::dependencyContext(dirname($matrixPath), $definition);

        for ($warmup = 0; $warmup < $warmups; ++$warmup) {
            $subject->runOnce();
        }

        $measurement = $subject->measureOnce();
        $operationsPerSample = $scenarioId === 'http_repeated_warm' ? $requestCount : 1;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'comparator_id' => $comparatorId,
            'scenario_id' => $scenarioId,
            'availability' => 'available',
            'availability_status' => 'available',
            'timing_boundary' => $subjectMetadata['timing_boundary'] ?? null,
            'prepared_framework_instance_count' => $subjectMetadata['prepared_framework_instance_count'] ?? null,
            'sample_count' => 1,
            'sample_index' => max(1, $sampleIndex),
            'samples' => [$measurement['duration_microseconds']],
            'unit' => 'microseconds',
            'memory' => $measurement['memory'],
            'warmups' => $warmups,
            'request_count' => $requestCount,
            'operations_per_sample' => $operationsPerSample,
            'worker_environment_identity' => $workerEnvironment,
            'dependency_context' => $dependencyContext,
            'process' => self::processMetadata(),
            'subject_metadata' => $subjectMetadata,
            'last_result' => $measurement['result'],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function loadFixture(string $baseDir, array $definition): ComparatorFixture
    {
        $fixtureRoot = $baseDir . DIRECTORY_SEPARATOR . trim((string) $definition['fixture_path'], '/\\');
        $autoloadPath = $fixtureRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        if (is_file($autoloadPath)) {
            require_once $autoloadPath;
        }

        $path = $baseDir . DIRECTORY_SEPARATOR . trim((string) $definition['fixture_bootstrap'], '/\\');
        $fixture = require $path;

        if (!$fixture instanceof ComparatorFixture) {
            throw new RuntimeException("Comparator '{$definition['id']}' bootstrap must return a ComparatorFixture.");
        }

        return $fixture;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private static function dependencyContext(string $baseDir, array $definition): array
    {
        $fixtureRoot = $baseDir . DIRECTORY_SEPARATOR . trim((string) $definition['fixture_path'], '/\\');
        $benchmarkRoot = dirname($baseDir);
        $included = array_map(static fn(string $path): string => str_replace('\\', '/', $path), get_included_files());
        $benchmarkAutoload = str_replace('\\', '/', $benchmarkRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');
        $frameworkClasses = [
            'evolvephp' => 'Evolve\Http\HttpKernel',
            'laravel' => 'Illuminate\Routing\Router',
            'symfony' => 'Symfony\Component\HttpKernel\HttpKernel',
            'slim' => 'Slim\App',
            'phalcon' => 'Phalcon\Mvc\Micro',
        ];
        $loadedFrameworks = [];

        foreach ($frameworkClasses as $id => $class) {
            $loadedFrameworks[$id] = class_exists($class, false);
        }

        $selectedId = (string) $definition['id'];
        $unrelatedFrameworks = $loadedFrameworks;
        unset($unrelatedFrameworks[$selectedId]);

        return [
            'selected_comparator_root' => self::relativePath(dirname($benchmarkRoot), $fixtureRoot),
            'selected_comparator_autoload' => self::relativePath(dirname($benchmarkRoot), $fixtureRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php'),
            'benchmark_root_autoload_loaded' => in_array($benchmarkAutoload, $included, true),
            'selected_framework_class' => $frameworkClasses[$selectedId] ?? null,
            'selected_framework_loaded' => $loadedFrameworks[$selectedId] ?? false,
            'unrelated_framework_classes_loaded' => $unrelatedFrameworks,
            'unrelated_framework_classes_preloaded' => $unrelatedFrameworks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function processMetadata(): array
    {
        return [
            'pid' => getmypid(),
            'php_binary' => PHP_BINARY,
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'cwd' => getcwd(),
        ];
    }

    private static function relativePath(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
    }
}
