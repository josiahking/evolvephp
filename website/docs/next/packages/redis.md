---
title: Redis PSR-16 compatibility
description: Application-owned Symfony Cache registration with a Redis backend.
sidebar_position: 3
---

# Redis PSR-16 compatibility

An EvolvePHP application can bind the standard `Psr\SimpleCache\CacheInterface` to Symfony Cache's `Psr16Cache` over `RedisAdapter`. Core does not select a cache backend or register this binding. This integration uses upstream packages directly; it does not add a first-party Redis package.

## Install and configure

In the consuming application, install `evolvephp/core`, `psr/simple-cache:^3.0`, `symfony/cache:^8.0`, and a Redis client such as `predis/predis:^3.0`. During the source preview, Core may need a Composer path repository to this checkout's `packages/core` and `packages/contracts`; see [source preview](../getting-started/source-preview.md). This repository's isolated consumer resolved Symfony Cache **8.1.11**, Predis **3.7.1**, and PSR Simple Cache **3.0.0** on PHP **8.4.25**. Those versions passed isolated installation and symbol checks; live Redis behavior requires the Redis compatibility workflow.

Set `REDIS_DSN` in the application environment, for example `redis://127.0.0.1:6379`. Use `rediss://` for a TLS endpoint and configure certificate verification. A running Redis service and either Predis or the PHP Redis extension are required. Keep credentials in secret-managed environment configuration; do not put them in source or logs. An unset DSN leaves the cache service unbound, so the application must decide whether to register its own fallback.

Register the service before freezing the registry. This example selects Predis explicitly; replace the client class with `Redis::class` only when the PHP Redis extension is installed.

~~~php
<?php
require __DIR__ . '/vendor/autoload.php';

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
    $cache->set('example', 'value', 3600);
    echo $cache->get('example', 'fallback');
}
~~~

`ServiceLifetime::Application` shares the cache object across execution scopes. Cache values are stored in Redis and survive process requests until deleted or expired. Avoid storing request, user, or tenant state in the shared object; use distinct namespaces for separate applications or environments. A positive TTL is in seconds. Require a nonempty, application-owned namespace before registering the cache. With an empty namespace, upstream `RedisAdapter::clear()` can flush the entire selected Redis database. The example uses `my_application`; replace it with a namespace owned by your application and environment.

Symfony Cache provides reserved-character key validation through `CacheItem::validateKey()`. Its PSR-16 wrapper calls that validator inside PHP assertions, so key rejection by the wrapper depends on assertions being enabled. The fixture probes the connection on first service resolution so an unavailable Redis service or rejected authentication is visible then. A later outage during cache operations may still be treated as a cache miss by Symfony Cache; the PSR-16 wrapper is not a strict availability monitor. Choose an application failure policy if a cache miss during an outage would change behavior. Invalid TLS settings and ACL restrictions need separate deployment checks. Avoid logging the DSN or cache values.

The [consumer fixture](https://github.com/josiahking/evolvephp/blob/2.x/tools/fixtures/redis-psr16-consumer.php) shows explicit registration with a client choice. The [compatibility validator](https://github.com/josiahking/evolvephp/blob/2.x/tools/validate-redis-compatibility.php) checks the PSR-16 methods, defaults, expiration, namespace isolation, and application lifetime against a live service when `REDIS_DSN` is set. Its local symbol mode uses an isolated Composer consumer via `EVOLVE_REDIS_CONSUMER_AUTOLOAD` and does not prove live Redis behavior. The dedicated CI workflow exercises Predis and the PHP Redis extension against Redis.
