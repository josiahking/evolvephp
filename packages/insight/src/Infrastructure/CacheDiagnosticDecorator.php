<?php

declare(strict_types=1);

namespace Evolve\Insight\Infrastructure;

use Psr\SimpleCache\CacheInterface;

final readonly class CacheDiagnosticDecorator implements CacheInterface
{
    public function __construct(private CacheInterface $cache, private DiagnosticRecorder $recorder) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->recorder->run('cache', 'get', fn(): mixed => $this->cache->get($key, $default));
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        return $this->boolean('set', fn(): bool => $this->cache->set($key, $value, $ttl));
    }

    public function delete(string $key): bool
    {
        return $this->boolean('delete', fn(): bool => $this->cache->delete($key));
    }

    public function clear(): bool
    {
        return $this->boolean('clear', fn(): bool => $this->cache->clear());
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->recorder->run('cache', 'getMultiple', fn(): iterable => $this->cache->getMultiple($keys, $default));
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        return $this->boolean('setMultiple', fn(): bool => $this->cache->setMultiple($values, $ttl));
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->boolean('deleteMultiple', fn(): bool => $this->cache->deleteMultiple($keys));
    }

    public function has(string $key): bool
    {
        return $this->boolean('has', fn(): bool => $this->cache->has($key));
    }

    /** @param callable(): bool $operation */
    private function boolean(string $name, callable $operation): bool
    {
        return $this->recorder->run('cache', $name, $operation, static fn(bool $result): array => ['result' => $result]);
    }
}
