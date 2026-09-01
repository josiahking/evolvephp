<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Comparator;

use JsonException;

final class ComparatorMatrix
{
    public const SCHEMA_VERSION = 'evolvephp.comparator.matrix.v1';

    private const IMPLEMENTATION_MODELS = ['pure-php', 'compiled-extension'];

    private const AVAILABILITY_STATES = ['always', 'conditional'];

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private readonly array $data,
    ) {}

    public static function fromJsonFile(string $path): self
    {
        if (!is_file($path)) {
            throw new ComparatorMatrixException("Matrix file not found: {$path}");
        }

        try {
            $data = json_decode(file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ComparatorMatrixException("Invalid matrix JSON: {$e->getMessage()}", previous: $e);
        }

        if (!is_array($data)) {
            throw new ComparatorMatrixException('Matrix data must be an array');
        }

        return self::fromArray($data, dirname($path));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $baseDir = ''): self
    {
        // Validate schema version
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new ComparatorMatrixException(
                'Invalid schema version. Expected: ' . self::SCHEMA_VERSION . ', Got: ' . ($data['schema_version'] ?? 'null'),
            );
        }

        $commonScenarios = $data['common_scenarios'] ?? [];
        if (!is_array($commonScenarios) || $commonScenarios === []) {
            throw new ComparatorMatrixException('Common scenarios must be a non-empty array');
        }

        foreach ($commonScenarios as $scenarioId => $scenario) {
            if (!is_array($scenario)) {
                throw new ComparatorMatrixException("Common scenario '{$scenarioId}' must be an array");
            }

            if (($scenario['id'] ?? null) !== $scenarioId) {
                throw new ComparatorMatrixException("Common scenario '{$scenarioId}' must repeat its stable id");
            }
        }

        // Validate comparators array
        $comparators = $data['comparators'] ?? [];
        if (!is_array($comparators)) {
            throw new ComparatorMatrixException('Comparators must be an array');
        }

        if (count($comparators) === 0) {
            throw new ComparatorMatrixException('At least one comparator must be defined');
        }

        // Validate individual comparators and check for duplicates
        $seenIds = [];
        foreach ($comparators as $comparator) {
            if (!is_array($comparator)) {
                throw new ComparatorMatrixException('Each comparator must be an array');
            }

            // Check required fields
            $requiredFields = [
                'id',
                'name',
                'framework_package',
                'framework_version',
                'composer_constraint',
                'fixture_path',
                'fixture_bootstrap',
                'lock_path',
                'implementation_model',
                'availability',
                'scenarios',
            ];
            foreach ($requiredFields as $field) {
                if (!isset($comparator[$field])) {
                    throw new ComparatorMatrixException("Comparator missing required field: {$field}");
                }
            }

            $id = (string) $comparator['id'];

            // Check for duplicates
            if (isset($seenIds[$id])) {
                throw new ComparatorMatrixException("Duplicate comparator ID: {$id}");
            }
            $seenIds[$id] = true;

            $implementationModel = (string) $comparator['implementation_model'];
            if (!in_array($implementationModel, self::IMPLEMENTATION_MODELS, true)) {
                throw new ComparatorMatrixException("Unsupported implementation model for comparator '{$id}': {$implementationModel}");
            }

            $availability = (string) $comparator['availability'];
            if (!in_array($availability, self::AVAILABILITY_STATES, true)) {
                throw new ComparatorMatrixException("Unsupported availability state for comparator '{$id}': {$availability}");
            }

            if ($availability === 'conditional' && !isset($comparator['availability_condition'])) {
                throw new ComparatorMatrixException("Conditional comparator '{$id}' must define availability_condition");
            }

            // Validate fixture directory exists if baseDir is provided
            if ($baseDir !== '') {
                $fixturePath = $baseDir . DIRECTORY_SEPARATOR . trim((string) $comparator['fixture_path'], '/\\');
                if (!is_dir($fixturePath)) {
                    throw new ComparatorMatrixException(
                        "Fixture directory not found for comparator '{$id}': {$fixturePath}",
                    );
                }

                $bootstrapPath = $baseDir . DIRECTORY_SEPARATOR . trim((string) $comparator['fixture_bootstrap'], '/\\');
                if (!is_file($bootstrapPath)) {
                    throw new ComparatorMatrixException(
                        "Fixture bootstrap not found for comparator '{$id}': {$bootstrapPath}",
                    );
                }

                $lockPath = $baseDir . DIRECTORY_SEPARATOR . trim((string) $comparator['lock_path'], '/\\');
                if (!is_file($lockPath)) {
                    throw new ComparatorMatrixException(
                        "Lockfile not found for comparator '{$id}': {$lockPath}",
                    );
                }
            }

            // Validate scenarios
            $scenarios = $comparator['scenarios'];
            if (!is_array($scenarios)) {
                throw new ComparatorMatrixException("Scenarios for comparator '{$id}' must be an array");
            }

            foreach ($scenarios as $scenario) {
                if (!is_string($scenario) || !isset($commonScenarios[$scenario])) {
                    throw new ComparatorMatrixException("Unknown scenario '{$scenario}' referenced by comparator '{$id}'");
                }
            }
        }

        return new self($data);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function comparators(): array
    {
        $result = [];
        $comparators = $this->data['comparators'] ?? [];

        // Ensure stable ordering
        usort($comparators, static fn(array $a, array $b): int => strcmp(
            (string) $a['id'],
            (string) $b['id'],
        ));

        foreach ($comparators as $comparator) {
            $result[(string) $comparator['id']] = $comparator;
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function commonScenarios(): array
    {
        return $this->data['common_scenarios'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function comparator(string $id): ?array
    {
        return $this->comparators()[$id] ?? null;
    }
}
