---
title: Redis PSR-16 compatibility
description: Use a PSR-16 cache in an EvolvePHP application, with optional Redis storage.
sidebar_position: 3
---

# Redis PSR-16 compatibility

## What a cache does

A cache keeps a copy of a value so an application can reuse it instead of doing the same work again. A cache entry may disappear at any time. Your application must still know how to produce the value when the entry is missing or expires. Redis can share cached values across application processes, while a local memory cache is limited to its own process.

EvolvePHP Core does not choose a cache backend or automatically register Redis. An application can depend on the standard `Psr\SimpleCache\CacheInterface` and choose an implementation during startup. The Redis option here uses upstream Symfony Cache; it does not add a first-party Redis package.

## Use the cache

Pass `CacheInterface` to a service through its constructor. The service uses the same PSR-16 methods regardless of which cache implementation the application registers.

~~~php
<?php

use Psr\SimpleCache\CacheInterface;

final class ExampleService
{
    private CacheInterface $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    public function run(): string
    {
        $this->cache->set('example', 'value', 3600);
        $value = $this->cache->get('example', 'fallback');
        $this->cache->delete('example');

        return (string) $value;
    }
}
~~~

`set()` writes a value; the third argument is a time-to-live (TTL) in seconds. `get()` reads it and returns the supplied fallback on a miss, including after expiration. `delete()` removes it. A cache miss should lead to a safe fallback or recomputation, not a loss of required data. The application creates `ExampleService` with its registered `CacheInterface` instance; this example does not assume a framework facade or global container helper. Composer autoloading happens once in the application bootstrap. Feature code does not manually reload `vendor/autoload.php`.

## Install optional packages

In the consuming application, install `evolvephp/core`, `psr/simple-cache:^3.0`, `symfony/cache:^8.0`, and a Redis client such as `predis/predis:^3.0`. During the source preview, Core may need a Composer path repository to this checkout's `packages/core` and `packages/contracts`; see [source preview](../getting-started/source-preview.md). This repository's isolated consumer resolved Symfony Cache **8.1.11**, Predis **3.7.1**, and PSR Simple Cache **3.0.0** on PHP **8.4.25**. Those versions passed isolated installation and symbol checks; live Redis behavior requires the Redis compatibility workflow.

## Configure Redis once at startup

Set `REDIS_DSN` in the application environment, for example `redis://127.0.0.1:6379`. Register the service before freezing the registry. This example selects Predis explicitly and leaves the binding absent when no DSN is configured. The application decides whether to register another cache implementation as a fallback.

~~~php
<?php

use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Psr\SimpleCache\CacheInterface;

$services = new ServiceRegistry();
$dsn = getenv('REDIS_DSN');
if (is_string($dsn) && $dsn !== '') {
    $services->register(CacheInterface::class, ServiceLifetime::Application,
        static function () use ($dsn): CacheInterface {
            $connection = \Symfony\Component\Cache\Adapter\RedisAdapter::createConnection(
                $dsn,
                ['class' => \Predis\Client::class]
            );
            $pool = new \Symfony\Component\Cache\Adapter\RedisAdapter($connection, 'my_application');

            return new \Symfony\Component\Cache\Psr16Cache($pool);
        });
}

$container = $services->freeze();
if ($container->has(CacheInterface::class)) {
    $cache = $container->get(CacheInterface::class);
    $service = new ExampleService($cache);
    echo $service->run();
}
~~~

The `ExampleService` class above would normally be autoloaded from an application source file. The application bootstrap loads Composer's autoloader once, creates the registry, and passes the resolved cache to services that need it.

## Advanced setup and safety

`ServiceLifetime::Application` shares the cache object across execution scopes. Cache values are stored in Redis and survive process requests until deleted or expired. Avoid storing request, user, or tenant state in the shared object; use distinct namespaces for separate applications or environments. Require a nonempty, application-owned namespace before registering the cache. With an empty namespace, upstream `RedisAdapter::clear()` can flush the entire selected Redis database. Replace `my_application` with a namespace owned by your application and environment.

A running Redis service and either Predis or the PHP Redis extension are required. The example uses Predis; select `Redis::class` only when the PHP Redis extension is installed. Use `rediss://` for a TLS endpoint and configure certificate verification. Keep Redis credentials in secret-managed configuration, never in source or logs. Verify authentication, TLS settings, and ACL permissions in the deployment environment. Avoid logging the DSN or cache values.

Symfony Cache provides reserved-character key validation through `CacheItem::validateKey()`. Its PSR-16 wrapper calls that validator inside PHP assertions, so key rejection by the wrapper depends on assertions being enabled. The fixture probes the connection on first service resolution so an unavailable Redis service or rejected authentication is visible then. A later outage during cache operations may still be treated as a cache miss by Symfony Cache; the PSR-16 wrapper is not a strict availability monitor. Choose an application failure policy if a cache miss during an outage would change behavior.

The [consumer fixture](https://github.com/josiahking/evolvephp/blob/2.x/tools/fixtures/redis-psr16-consumer.php) shows explicit registration with a client choice. The [compatibility validator](https://github.com/josiahking/evolvephp/blob/2.x/tools/validate-redis-compatibility.php) checks the PSR-16 methods, defaults, expiration, namespace isolation, and application lifetime against a live service when `REDIS_DSN` is set. Its local symbol mode uses an isolated Composer consumer via `EVOLVE_REDIS_CONSUMER_AUTOLOAD` and does not prove live Redis behavior. The dedicated CI workflow exercises Predis and the PHP Redis extension against Redis.