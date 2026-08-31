<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Support;

final class EnvironmentFingerprint
{
    /**
     * @param array<string, mixed> $environment
     * @return array{hash: string, fields: array<string, mixed>}
     */
    public static function fromEnvironment(array $environment): array
    {
        $fields = [
            'schema_version' => $environment['schema_version'] ?? null,
            'runtime' => $environment['runtime'] ?? [],
            'platform' => $environment['platform'] ?? [],
            'composer_version' => $environment['composer']['version'] ?? null,
            'phpbench_version' => $environment['phpbench']['version'] ?? null,
            'opcache' => $environment['opcache'] ?? [],
            'jit' => $environment['jit'] ?? [],
            'extensions' => $environment['extensions'] ?? [],
            'lock_hash' => $environment['lock']['hash'] ?? null,
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
