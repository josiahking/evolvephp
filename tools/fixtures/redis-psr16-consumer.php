<?php

declare(strict_types=1);

use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Predis\Client as PredisClient;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Application-owned PSR-16 registration. A missing DSN leaves the binding absent.
 */
function registerRedisCache(ServiceRegistry $services, ?string $dsn, string $namespace, string $client): bool
{
    if ($dsn === null || $dsn === '') {
        return false;
    }
    if (trim($namespace) === '') {
        throw new InvalidArgumentException('Redis cache namespace must be nonempty.');
    }

    $class = match ($client) {
        'predis' => PredisClient::class,
        'extension' => Redis::class,
        default => throw new InvalidArgumentException('Unsupported Redis client.'),
    };
    if ($client === 'predis' && !class_exists(PredisClient::class)) {
        throw new RuntimeException('Predis client is not installed.');
    }
    if ($client === 'extension' && !extension_loaded('redis')) {
        throw new RuntimeException('Redis extension is not installed.');
    }

    $services->register(
        CacheInterface::class,
        ServiceLifetime::Application,
        static function () use ($dsn, $namespace, $class): CacheInterface {
            $connection = RedisAdapter::createConnection($dsn, [
                'class' => $class,
                'timeout' => 1.0,
                'read_timeout' => 1.0,
                'lazy' => false,
            ]);
            // Probe before Symfony disables Predis exceptions for cache operations.
            if ($connection instanceof PredisClient) {
                $connection->ping();
            } elseif ($connection instanceof Redis && $connection->ping() === false) {
                throw new RuntimeException('Redis connection probe failed.');
            }

            return new Psr16Cache(new RedisAdapter($connection, $namespace));
        },
    );

    return true;
}
