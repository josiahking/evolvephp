<?php

declare(strict_types=1);

use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Predis\Client as PredisClient;
use Predis\Response\ErrorInterface as PredisError;
use Predis\Response\ServerException;
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
            // Symfony disables Predis exceptions, so check authentication and probe responses explicitly.
            if ($connection instanceof PredisClient) {
                $parameters = $connection->getConnection()->getParameters();
                if (isset($parameters->password) && $parameters->password !== '') {
                    $authentication = ($parameters->username ?? 'default') === 'default'
                        ? $connection->auth($parameters->password)
                        : $connection->auth($parameters->username, $parameters->password);
                    if ($authentication instanceof PredisError) {
                        throw new ServerException('AUTH rejected by Redis.');
                    }
                }
                $probe = $connection->ping();
                if ($probe instanceof PredisError) {
                    throw new ServerException('Redis connection probe rejected.');
                }
            } elseif ($connection instanceof Redis && $connection->ping() === false) {
                throw new RuntimeException('Redis connection probe failed.');
            }

            return new Psr16Cache(new RedisAdapter($connection, $namespace));
        },
    );

    return true;
}
