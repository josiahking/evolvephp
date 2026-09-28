<?php

declare(strict_types=1);

namespace Evolve\Cache\Memory\Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use Evolve\Cache\Memory\Exception\InvalidCacheKeyException;
use Evolve\Cache\Memory\InMemoryCache;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use stdClass;

final class InMemoryCacheTest extends TestCase
{
    public function testBasicCacheBehaviorPreservesValuesAndInstanceIsolation(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $cache = new InMemoryCache($clock);
        $other = new InMemoryCache($clock);
        $object = new stdClass();
        $object->name = 'stored';

        self::assertSame('fallback', $cache->get('missing', 'fallback'));
        self::assertTrue($cache->set('string', 'value'));
        self::assertTrue($cache->set('integer', 123));
        self::assertTrue($cache->set('float', 12.5));
        self::assertTrue($cache->set('bool', false));
        self::assertTrue($cache->set('array', ['nested' => ['value']]));
        self::assertTrue($cache->set('object', $object));
        self::assertTrue($cache->set('null', null));

        self::assertSame('value', $cache->get('string'));
        self::assertSame(123, $cache->get('integer'));
        self::assertSame(12.5, $cache->get('float'));
        self::assertFalse($cache->get('bool'));
        self::assertSame(['nested' => ['value']], $cache->get('array'));
        self::assertSame($object, $cache->get('object'));
        self::assertNull($cache->get('null'));
        self::assertTrue($cache->has('null'));

        self::assertTrue($cache->set('string', 'updated'));
        self::assertSame('updated', $cache->get('string'));
        self::assertTrue($cache->delete('string'));
        self::assertSame('fallback', $cache->get('string', 'fallback'));
        self::assertTrue($cache->delete('absent'));

        self::assertTrue($other->set('only-other', 'value'));
        self::assertTrue($cache->clear());
        self::assertSame('fallback', $cache->get('integer', 'fallback'));
        self::assertSame('value', $other->get('only-other'));
    }

    public function testTtlBehaviorUsesInjectedClock(): void
    {
        $clock = new MutableClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $cache = new InMemoryCache($clock);

        self::assertTrue($cache->set('seconds', 'value', 10));
        self::assertSame('value', $cache->get('seconds'));
        $clock->advance(11);
        self::assertSame('expired', $cache->get('seconds', 'expired'));
        self::assertFalse($cache->has('seconds'));

        self::assertTrue($cache->set('zero', 'value', 0));
        self::assertSame('expired', $cache->get('zero', 'expired'));
        self::assertTrue($cache->set('negative', 'value', -1));
        self::assertSame('expired', $cache->get('negative', 'expired'));

        self::assertTrue($cache->set('interval', 'value', new DateInterval('PT5S')));
        self::assertSame('value', $cache->get('interval'));
        $clock->advance(5);
        self::assertSame('expired', $cache->get('interval', 'expired'));

        $past = new DateInterval('PT1S');
        $past->invert = 1;
        self::assertTrue($cache->set('past-interval', 'value', $past));
        self::assertSame('expired', $cache->get('past-interval', 'expired'));

        self::assertTrue($cache->set('forever', 'value', null));
        $clock->advance(3600);
        self::assertSame('value', $cache->get('forever'));
    }

    public function testInvalidKeysAreRejectedForSingleKeyOperations(): void
    {
        $cache = new InMemoryCache(new MutableClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')));

        foreach (['', '{', '}', '(', ')', '/', '\\', '@', ':'] as $key) {
            foreach (['get', 'set', 'delete', 'has'] as $method) {
                try {
                    match ($method) {
                        'get' => $cache->get($key),
                        'set' => $cache->set($key, 'value'),
                        'delete' => $cache->delete($key),
                        'has' => $cache->has($key),
                    };
                } catch (InvalidCacheKeyException) {
                    $this->addToAssertionCount(1);

                    continue;
                }

                self::fail(sprintf('%s should reject invalid key "%s".', $method, $key));
            }
        }
    }

    public function testValidOrdinaryPsrKeysWork(): void
    {
        $cache = new InMemoryCache(new MutableClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')));

        foreach (['alpha', 'alpha.beta', 'alpha_beta', 'alpha-beta', 'alpha123'] as $key) {
            self::assertTrue($cache->set($key, $key));
            self::assertSame($key, $cache->get($key));
        }
    }

    public function testBulkOperationsPreserveOrderAndDefaults(): void
    {
        $cache = new InMemoryCache(new MutableClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')));

        self::assertTrue($cache->setMultiple(['b' => 'bee', 'a' => 'aye']));
        self::assertSame(
            ['a' => 'aye', 'missing' => 'fallback', 'b' => 'bee'],
            iterator_to_array($cache->getMultiple(['a', 'missing', 'b'], 'fallback')),
        );
        self::assertTrue($cache->deleteMultiple(['a', 'b']));
        self::assertSame(
            ['a' => 'fallback', 'b' => 'fallback'],
            iterator_to_array($cache->getMultiple(['a', 'b'], 'fallback')),
        );
    }

    public function testBulkOperationsRejectInvalidKeysBeforeMutation(): void
    {
        $cache = new InMemoryCache(new MutableClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        self::assertTrue($cache->set('existing', 'original'));

        foreach ([
            'getMultiple' => static fn() => $cache->getMultiple(['existing', 'bad/key']),
            'setMultiple' => static fn() => $cache->setMultiple(['new' => 'value', 'bad/key' => 'value']),
            'deleteMultiple' => static fn() => $cache->deleteMultiple(['existing', 'bad/key']),
        ] as $operation => $call) {
            try {
                $call();
            } catch (InvalidCacheKeyException) {
                self::assertSame('original', $cache->get('existing'), $operation . ' should not mutate before validation succeeds.');
                self::assertSame('missing', $cache->get('new', 'missing'), $operation . ' should not partially add values.');

                continue;
            }

            self::fail($operation . ' should reject invalid keys.');
        }
    }
}

final class MutableClock implements ClockInterface
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d seconds', $seconds));
    }
}
