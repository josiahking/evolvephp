<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Support;

/**
 * FixtureIdentity represents the fixture-specific characteristics.
 *
 * This identity INCLUDES lock_hash and other fixture-specific metadata
 * to distinguish between different framework implementations.
 */
final class FixtureIdentity
{
    /**
     * @param array<string, mixed> $data
     * @return array{hash: string, fields: array<string, mixed>}
     */
    public static function fromArray(array $data): array
    {
        $fields = [
            'comparator_id' => $data['comparator_id'] ?? null,
            'framework_name' => $data['framework_name'] ?? null,
            'framework_version' => $data['framework_version'] ?? null,
            'fixture_version' => $data['fixture_version'] ?? null,
            'lock_hash' => $data['lock_hash'] ?? null,
            'configuration' => $data['configuration'] ?? [],
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
