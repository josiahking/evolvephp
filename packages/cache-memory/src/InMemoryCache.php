<?php

declare(strict_types=1);

namespace Evolve\Cache\Memory;

use DateInterval;
use DateTimeImmutable;
use Evolve\Cache\Memory\Exception\InvalidCacheKeyException;
use Evolve\Cache\Memory\Internal\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * @experimental
 */
final class InMemoryCache implements CacheInterface
{
    /**
     * @var array<string, array{value: mixed, expiresAt: DateTimeImmutable|null}>
     */
    private array $entries = [];

    public function __construct(
        private ClockInterface $clock = new SystemClock(),
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        self::assertValidKey($key);

        if (! $this->hasUnexpiredEntry($key)) {
            return $default;
        }

        return $this->entries[$key]['value'];
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        self::assertValidKey($key);

        $expiresAt = $this->expiresAt($ttl);

        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            unset($this->entries[$key]);

            return true;
        }

        $this->entries[$key] = [
            'value' => $value,
            'expiresAt' => $expiresAt,
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        self::assertValidKey($key);

        unset($this->entries[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = self::validatedKeys($keys);
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
    {
        $validatedValues = self::validatedKeyedValues($values);
        $expiresAt = $this->expiresAt($ttl);

        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            foreach (array_keys($validatedValues) as $key) {
                unset($this->entries[$key]);
            }

            return true;
        }

        foreach ($validatedValues as $key => $value) {
            $this->entries[$key] = [
                'value' => $value,
                'expiresAt' => $expiresAt,
            ];
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $keys = self::validatedKeys($keys);

        foreach ($keys as $key) {
            unset($this->entries[$key]);
        }

        return true;
    }

    public function has(string $key): bool
    {
        self::assertValidKey($key);

        return $this->hasUnexpiredEntry($key);
    }

    private function hasUnexpiredEntry(string $key): bool
    {
        if (! array_key_exists($key, $this->entries)) {
            return false;
        }

        $expiresAt = $this->entries[$key]['expiresAt'];

        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            unset($this->entries[$key]);

            return false;
        }

        return true;
    }

    private function expiresAt(int|DateInterval|null $ttl): ?DateTimeImmutable
    {
        if ($ttl === null) {
            return null;
        }

        $now = $this->clock->now();

        if (is_int($ttl)) {
            return $now->modify(sprintf('%+d seconds', $ttl));
        }

        return $now->add($ttl);
    }

    private static function assertValidKey(string $key): void
    {
        if ($key === '' || strpbrk($key, '{}()/\\@:') !== false) {
            throw new InvalidCacheKeyException('Invalid PSR-16 cache key.');
        }
    }

    /**
     * @param iterable<mixed> $keys
     *
     * @return list<string>
     */
    private static function validatedKeys(iterable $keys): array
    {
        $validated = [];

        foreach ($keys as $key) {
            if (! is_string($key)) {
                throw new InvalidCacheKeyException('Invalid PSR-16 cache key.');
            }

            self::assertValidKey($key);
            $validated[] = $key;
        }

        return $validated;
    }

    /**
     * @param iterable<mixed, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function validatedKeyedValues(iterable $values): array
    {
        $validated = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidCacheKeyException('Invalid PSR-16 cache key.');
            }

            self::assertValidKey($key);
            $validated[$key] = $value;
        }

        return $validated;
    }
}
