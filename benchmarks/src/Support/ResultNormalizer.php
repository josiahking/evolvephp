<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Support;

use RuntimeException;
use SimpleXMLElement;

final class ResultNormalizer
{
    public const SCHEMA_VERSION = 'evolvephp.benchmark.results.v1';

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $environment = $raw['environment'] ?? [];
        $normalized = [
            'schema_version' => self::SCHEMA_VERSION,
            'source_sha' => $environment['source']['git_sha'] ?? null,
            'environment_fingerprint' => $environment['fingerprint']['hash'] ?? null,
            'generated_at' => gmdate(DATE_ATOM),
            'scenarios' => [],
        ];

        foreach ($raw['scenarios'] ?? [] as $scenario) {
            if (!is_array($scenario)) {
                continue;
            }

            $samples = array_values(array_map('floatval', $scenario['samples'] ?? []));
            $normalized['scenarios'][] = self::normalizeScenario(
                (string) ($scenario['id'] ?? 'unknown'),
                $samples,
                (string) ($scenario['unit'] ?? 'microseconds'),
                is_array($scenario['memory'] ?? null) ? $scenario['memory'] : [],
            );
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $environment
     * @return array<string, mixed>
     */
    public static function fromPhpBenchXml(string $xmlPath, array $environment): array
    {
        if (!is_file($xmlPath)) {
            throw new RuntimeException('PHPBench XML result file does not exist.');
        }

        $xml = simplexml_load_file($xmlPath);

        if (!$xml instanceof SimpleXMLElement) {
            throw new RuntimeException('PHPBench XML result file could not be parsed.');
        }

        $scenarios = [];

        foreach ($xml->xpath('//benchmark') ?: [] as $benchmark) {
            $benchmarkClass = (string) $benchmark['class'];

            foreach ($benchmark->subject as $subject) {
                $subjectName = (string) $subject['name'];

                foreach ($subject->variant as $variant) {
                    $parameterSet = $variant->{'parameter-set'};
                    $variantName = (string) ($parameterSet['name'] ?? '');
                    $id = trim($benchmarkClass . '::' . $subjectName . ($variantName !== '' ? '#' . $variantName : ''));
                    $revs = max(1, (int) ($variant['revs'] ?? 1));
                    $samples = [];
                    $memory = [];

                    foreach ($variant->iteration as $iteration) {
                        $attributes = $iteration->attributes();

                        if (isset($attributes['time-net'])) {
                            $samples[] = ((float) $attributes['time-net']) / $revs;
                        }

                        if (isset($attributes['mem-final'])) {
                            $memory['current_bytes'] = (int) $attributes['mem-final'];
                        }

                        if (isset($attributes['mem-peak'])) {
                            $memory['peak_bytes'] = max($memory['peak_bytes'] ?? 0, (int) $attributes['mem-peak']);
                        }
                    }

                    $scenarios[] = [
                        'id' => $id,
                        'samples' => $samples,
                        'unit' => 'microseconds',
                        'memory' => $memory,
                    ];
                }
            }
        }

        return self::normalize([
            'environment' => $environment,
            'scenarios' => $scenarios,
        ]);
    }

    /**
     * @param list<float> $samples
     * @param array<string, mixed> $memory
     * @return array<string, mixed>
     */
    private static function normalizeScenario(string $id, array $samples, string $unit, array $memory): array
    {
        sort($samples, SORT_NUMERIC);
        $count = count($samples);
        $sum = array_sum($samples);
        $mean = $count > 0 ? $sum / $count : null;
        $standardDeviation = $count > 1 && $mean !== null ? self::standardDeviation($samples, $mean) : null;

        return [
            'id' => $id,
            'unit' => $unit,
            'sample_count' => $count,
            'total' => $count > 0 ? $sum : null,
            'mean' => $mean,
            'min' => $count > 0 ? min($samples) : null,
            'max' => $count > 0 ? max($samples) : null,
            'p50' => self::percentile($samples, 50, 1),
            'p50_status' => $count >= 1 ? 'available' : 'insufficient_samples',
            'p95' => self::tailPercentile($samples, 95, 20),
            'p95_status' => $count >= 20 ? 'available' : 'insufficient_samples',
            'p99' => self::tailPercentile($samples, 99, 100),
            'p99_status' => $count >= 100 ? 'available' : 'insufficient_samples',
            'relative_standard_deviation_percent' => $mean !== null && $mean > 0 && $standardDeviation !== null
                ? ($standardDeviation / $mean) * 100
                : null,
            'throughput_per_second' => $mean !== null && $mean > 0 ? 1_000_000 / $mean : null,
            'memory' => $memory,
        ];
    }

    /**
     * @param list<float> $samples
     */
    private static function percentile(array $samples, int $percentile, int $minimumSamples): ?float
    {
        $count = count($samples);

        if ($count < $minimumSamples) {
            return null;
        }

        if ($count === 1) {
            return $samples[0];
        }

        $position = ($percentile / 100) * ($count - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        if ($lower === $upper) {
            return $samples[$lower];
        }

        return $samples[$lower] + (($samples[$upper] - $samples[$lower]) * ($position - $lower));
    }

    /**
     * @param list<float> $samples
     */
    private static function tailPercentile(array $samples, int $percentile, int $minimumSamples): ?float
    {
        $count = count($samples);

        if ($count < $minimumSamples) {
            return null;
        }

        $index = max(0, min($count - 1, (int) ceil(($percentile / 100) * $count) - 1));

        return $samples[$index];
    }

    /**
     * @param list<float> $samples
     */
    private static function standardDeviation(array $samples, float $mean): float
    {
        $sum = 0.0;

        foreach ($samples as $sample) {
            $sum += ($sample - $mean) ** 2;
        }

        return sqrt($sum / (count($samples) - 1));
    }
}
