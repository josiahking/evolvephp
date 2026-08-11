<?php

declare(strict_types=1);

namespace Evolve\Core\Configuration;

use Evolve\Contracts\Configuration\Configuration;
use Evolve\Core\Exception\InvalidConfiguration;

final class ArrayConfiguration implements Configuration
{
    /**
     * @var array<string, mixed>
     */
    private array $values;

    /**
     * @param array<mixed> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = self::normalizeRoot($values);
    }

    public function has(string $key): bool
    {
        return $this->find($key)[0];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->find($key);

        if (! $found) {
            return $default;
        }

        return $value;
    }

    public function require(string $key): mixed
    {
        [$found, $value] = $this->find($key);

        if (! $found) {
            throw new InvalidConfiguration('Required configuration value is missing.');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function normalizeRoot(array $values): array
    {
        if ($values === []) {
            return [];
        }

        if (array_is_list($values)) {
            throw new InvalidConfiguration('Root configuration must be an associative map.');
        }

        return self::normalizeAssociativeMap($values);
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            return self::normalizeNestedArray($value);
        }

        throw new InvalidConfiguration('Configuration values must be scalar, null or recursive arrays.');
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private static function normalizeNestedArray(array $values): array
    {
        if ($values === []) {
            return [];
        }

        if (array_is_list($values)) {
            $normalized = [];

            foreach ($values as $value) {
                $normalized[] = self::normalizeValue($value);
            }

            return $normalized;
        }

        return self::normalizeAssociativeMap($values);
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function normalizeAssociativeMap(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidConfiguration('Associative configuration keys must be strings.');
            }

            if ($key === '' || str_contains($key, '.')) {
                throw new InvalidConfiguration('Associative configuration keys must be non-empty and must not contain dots.');
            }

            $normalized[$key] = self::normalizeValue($value);
        }

        return $normalized;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function find(string $key): array
    {
        $segments = self::pathSegments($key);
        $current = $this->values;

        foreach ($segments as $segment) {
            if (! is_array($current) || array_is_list($current)) {
                return [false, null];
            }

            if (! array_key_exists($segment, $current)) {
                return [false, null];
            }

            $current = $current[$segment];
        }

        return [true, $current];
    }

    /**
     * @return non-empty-list<string>
     */
    private static function pathSegments(string $key): array
    {
        if ($key === '' || str_starts_with($key, '.') || str_ends_with($key, '.') || str_contains($key, '..')) {
            throw new InvalidConfiguration('Configuration path is malformed.');
        }

        $segments = explode('.', $key);

        foreach ($segments as $segment) {
            if ($segment === '' || ctype_digit($segment)) {
                throw new InvalidConfiguration('Configuration path is malformed.');
            }
        }

        return $segments;
    }
}
