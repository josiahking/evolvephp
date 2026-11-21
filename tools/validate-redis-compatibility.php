<?php

declare(strict_types=1);

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ServiceResolutionFailed;
use Predis\Connection\ConnectionException;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\Response\ServerException;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as InvalidCacheKey;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\Exception\InvalidArgumentException as InvalidRedisConfiguration;
use Symfony\Component\Cache\Psr16Cache;

$autoload = getenv('EVOLVE_REDIS_CONSUMER_AUTOLOAD');
if (!is_string($autoload) || !is_file($autoload)) {
    fwrite(STDERR, "Set EVOLVE_REDIS_CONSUMER_AUTOLOAD to the isolated consumer autoload.php.\n");
    exit(2);
}
require $autoload;
require __DIR__ . '/fixtures/redis-psr16-consumer.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectedFailure(callable $action, string|array $categories, string $message, ?callable $matches = null): void
{
    try {
        $action();
    } catch (Throwable $error) {
        $cause = $error instanceof ServiceResolutionFailed ? $error->getPrevious() : $error;
        $expected = false;
        foreach ((array) $categories as $category) {
            if ($cause instanceof $category) {
                $expected = true;
                break;
            }
        }
        if (!$expected || ($matches !== null && !$matches($cause))) {
            throw new RuntimeException($message . ' Unexpected exception category: ' . get_class($error) . '.');
        }
        return;
    }
    throw new RuntimeException($message);
}

try {
    foreach ([ServiceRegistry::class, CacheInterface::class, RedisAdapter::class, Psr16Cache::class] as $symbol) {
        check(class_exists($symbol) || interface_exists($symbol), 'Missing required consumer symbol.');
    }
    $empty = new ServiceRegistry();
    check(!registerRedisCache($empty, null, 'evolve_redis_compat', 'predis'), 'Missing DSN registered a cache.');
    check(!$empty->freeze()->has(CacheInterface::class), 'Missing DSN bound a cache.');
    $unsafeNamespace = new ServiceRegistry();
    expectedFailure(
        static fn() => registerRedisCache($unsafeNamespace, 'redis://127.0.0.1', '', 'predis'),
        InvalidArgumentException::class,
        'Empty Redis namespace was accepted.',
        static fn(Throwable $error): bool => $error->getMessage() === 'Redis cache namespace must be nonempty.',
    );
    check(!$unsafeNamespace->freeze()->has(CacheInterface::class), 'Empty namespace registered a cache.');
    expectedFailure(
        static function (): void {
            $registry = new ServiceRegistry();
            registerRedisCache($registry, 'invalid://127.0.0.1', 'evolve_redis_compat', 'predis');
            $registry->freeze()->get(CacheInterface::class);
        },
        InvalidRedisConfiguration::class,
        'Malformed DSN was accepted.',
        static fn(Throwable $error): bool => str_starts_with($error->getMessage(), 'Invalid Redis DSN:'),
    );
    expectedFailure(
        static function (): void {
            registerRedisCache(new ServiceRegistry(), 'redis://127.0.0.1', 'evolve_redis_compat', 'unsupported');
        },
        InvalidArgumentException::class,
        'Unknown client was accepted.',
        static fn(Throwable $error): bool => $error->getMessage() === 'Unsupported Redis client.',
    );
    if (!extension_loaded('redis')) {
        expectedFailure(
            static function (): void {
                registerRedisCache(new ServiceRegistry(), 'redis://127.0.0.1', 'evolve_redis_compat', 'extension');
            },
            RuntimeException::class,
            'Missing Redis extension was accepted.',
            static fn(Throwable $error): bool => $error->getMessage() === 'Redis extension is not installed.',
        );
    }
    expectedFailure(
        static fn() => CacheItem::validateKey('bad{}key'),
        InvalidCacheKey::class,
        'Invalid PSR-16 key was accepted.',
    );
    $programmingErrorRejected = false;
    try {
        expectedFailure(static fn() => throw new TypeError('sentinel'), InvalidArgumentException::class, 'Programming error was accepted.');
    } catch (RuntimeException $error) {
        $programmingErrorRejected = $error->getMessage() === 'Programming error was accepted. Unexpected exception category: TypeError.';
    }
    check($programmingErrorRejected, 'Unexpected programming error was treated as an expected negative.');

    $mode = $argv[1] ?? '--symbols';
    if ($mode === '--negative') {
        $client = getenv('EVOLVE_REDIS_CLIENT') ?: 'predis';
        expectedFailure(
            static function () use ($client): void {
                $registry = new ServiceRegistry();
                registerRedisCache($registry, 'redis://127.0.0.1:1', 'evolve_redis_unavailable', $client);
                $registry->freeze()->get(CacheInterface::class)->get('probe');
            },
            $client === 'predis' ? StreamInitException::class : InvalidRedisConfiguration::class,
            'Unavailable Redis was accepted.',
        );
        $dsn = getenv('REDIS_DSN');
        if (is_string($dsn) && $dsn !== '') {
            expectedFailure(
                static function () use ($client): void {
                    $registry = new ServiceRegistry();
                    registerRedisCache($registry, 'redis://invalid@127.0.0.1:6379', 'evolve_redis_auth', $client);
                    $registry->freeze()->get(CacheInterface::class)->get('probe');
                },
                $client === 'predis' ? [ConnectionException::class, ServerException::class] : InvalidRedisConfiguration::class,
                'Invalid Redis authorization was accepted.',
                static fn(Throwable $error): bool => preg_match('/\b(?:AUTH|WRONGPASS|NOAUTH)\b/i', $error->getMessage()) === 1,
            );
        }
        echo is_string($dsn) && $dsn !== ''
            ? "PASS unavailable Redis and rejected authentication client={$client}\n"
            : "PASS unavailable Redis client={$client}; authentication check needs live Redis\n";
        exit(0);
    }
    if ($mode !== '--live') {
        check($mode === '--symbols', 'Expected --symbols, --negative, or --live.');
        echo "PASS isolated consumer symbols and configuration negatives\n";
        exit(0);
    }

    $dsn = getenv('REDIS_DSN');
    $client = getenv('EVOLVE_REDIS_CLIENT') ?: 'predis';
    check(is_string($dsn) && $dsn !== '', 'REDIS_DSN is required for live tests.');
    $namespace = 'evolve_compat_' . bin2hex(random_bytes(5));
    $registry = new ServiceRegistry();
    check(registerRedisCache($registry, $dsn, $namespace, $client), 'Cache was not registered.');
    $root = $registry->freeze();
    $cache = $root->get(CacheInterface::class);
    check($cache instanceof CacheInterface, 'Binding did not provide PSR-16.');
    $scope = $registry->createExecutionScope();
    try {
        check($scope->get(CacheInterface::class) === $cache, 'Application lifetime was not reused.');
    } finally {
        $scope->close();
    }

    $otherRegistry = new ServiceRegistry();
    registerRedisCache($otherRegistry, $dsn, $namespace . '_other', $client);
    $other = $otherRegistry->freeze()->get(CacheInterface::class);
    try {
        check($cache->get('missing', 'fallback') === 'fallback', 'Default value failed.');
        check($cache->set('one', 'value'), 'set failed.');
        check($cache->get('one') === 'value', 'get failed.');
        check($cache->has('one'), 'has failed.');
        check($other->get('one') === null, 'Namespace leaked into another cache.');
        check($cache->setMultiple(['two' => 2, 'three' => 3]), 'setMultiple failed.');
        $multiple = [];
        foreach ($cache->getMultiple(['two', 'three', 'absent'], 'fallback') as $key => $value) {
            $multiple[$key] = $value;
        }
        check($multiple === ['two' => 2, 'three' => 3, 'absent' => 'fallback'], 'getMultiple/default failed.');
        check($cache->delete('one'), 'delete failed.');
        check(!$cache->has('one'), 'delete did not remove value.');
        check($cache->deleteMultiple(['two', 'three']), 'deleteMultiple failed.');
        check($cache->get('two') === null && $cache->get('three') === null, 'deleteMultiple did not remove values.');
        check($cache->set('expires', 'short', 1), 'TTL set failed.');
        sleep(2);
        check($cache->get('expires') === null, 'TTL did not expire.');
        check($cache->set('clear-me', 'x'), 'Pre-clear set failed.');
        check($other->set('keep-me', 'y'), 'Other namespace set failed.');
        check($cache->clear(), 'clear failed.');
        check($cache->get('clear-me') === null, 'clear did not remove own key.');
        check($other->get('keep-me') === 'y', 'clear removed another namespace.');
        expectedFailure(static fn() => CacheItem::validateKey('bad{}key'), InvalidCacheKey::class, 'Invalid PSR-16 key was accepted.');
    } finally {
        $cache->clear();
        $other->clear();
    }
    echo "PASS live Redis PSR-16 client={$client}\n";
} catch (Throwable $error) {
    // Upstream errors can contain credentials or values.
    fwrite(STDERR, "Redis compatibility validation failed: " . get_class($error) . "\n");
    exit(1);
}
