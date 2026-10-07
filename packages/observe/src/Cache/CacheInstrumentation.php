<?php

declare(strict_types=1);

namespace Evolve\Observe\Cache;

use DateInterval;
use Evolve\Observe\EvolveSemanticConventions as Names;
use Evolve\Observe\MetricCardinalityPolicy;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Psr\SimpleCache\CacheInterface;
use Throwable;

final class CacheInstrumentation implements CacheInterface
{
    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(
        private OpenTelemetryComposition $composition,
        private CacheInterface $cache,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->measure('get', fn(): mixed => $this->cache->get($key, $default));
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        return $this->measure('set', fn(): bool => $this->cache->set($key, $value, $ttl));
    }

    public function delete(string $key): bool
    {
        return $this->measure('delete', fn(): bool => $this->cache->delete($key));
    }

    public function clear(): bool
    {
        return $this->measure('clear', fn(): bool => $this->cache->clear());
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->measure('getMultiple', fn(): iterable => $this->cache->getMultiple($keys, $default));
    }

    /** @param iterable<array-key, mixed> $values */
    public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
    {
        return $this->measure('setMultiple', fn(): bool => $this->cache->setMultiple($values, $ttl));
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->measure('deleteMultiple', fn(): bool => $this->cache->deleteMultiple($keys));
    }

    public function has(string $key): bool
    {
        return $this->measure('has', fn(): bool => $this->cache->has($key));
    }

    /** @param callable(): mixed $delegate */
    private function measure(string $operation, callable $delegate): mixed
    {
        if (!$this->composition->isEnabled()) {
            return $delegate();
        }

        $span = null;
        $meter = null;
        try {
            if ($this->composition->tracerProvider() !== null) {
                $span = $this->composition->tracerProvider()->getTracer('evolvephp/observe')
                    ->spanBuilder(Names::SPAN_NAME_CACHE)
                    ->setParent(Context::getCurrent())
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(Names::ATTRIBUTE_CACHE_OPERATION, $operation)
                    ->startSpan();
            }
        } catch (Throwable) {
            $this->endSpan($span);
            $span = null;
        }
        try {
            if ($this->composition->meterProvider() !== null) {
                $meter = $this->composition->meterProvider()->getMeter('evolvephp/observe');
                $meter->createHistogram(Names::METRIC_CACHE_DURATION, 's');
                $meter->createCounter(Names::METRIC_CACHE_COUNT, '{operation}');
                $meter->createCounter(Names::METRIC_CACHE_FAILURES, '{operation}');
            }
        } catch (Throwable) {
            $meter = null;
        }

        $started = null;
        if ($meter !== null) {
            try {
                $started = ($this->clock)();
            } catch (Throwable) {
                // A clock failure cannot prevent the cache call.
            }
        }

        $failure = null;
        $result = null;
        try {
            $result = $delegate();
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        if ($meter !== null) {
            $attributes = MetricCardinalityPolicy::cacheAttributes($operation);
            if ($started !== null) {
                try {
                    $elapsed = max(0, (($this->clock)() - $started) / 1_000_000_000);
                    $meter->createHistogram(Names::METRIC_CACHE_DURATION, 's')->record($elapsed, $attributes);
                } catch (Throwable) {
                    // Continue recording independent instruments.
                }
            }
            try {
                $meter->createCounter(Names::METRIC_CACHE_COUNT, '{operation}')->add(1, $attributes);
            } catch (Throwable) {
                // Continue recording independent instruments.
            }
            if ($failure !== null) {
                try {
                    $meter->createCounter(Names::METRIC_CACHE_FAILURES, '{operation}')->add(1, $attributes);
                } catch (Throwable) {
                    // Telemetry cannot change cache behavior.
                }
            }
        }

        if ($failure !== null && $span !== null) {
            try {
                $span->setAttribute(ErrorAttributes::ERROR_TYPE, $failure::class);
                $span->setStatus(StatusCode::STATUS_ERROR);
            } catch (Throwable) {
                // Preserve the original cache throwable.
            }
        }
        $this->endSpan($span);
        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }

    private function endSpan(?SpanInterface $span): void
    {
        try {
            $span?->end();
        } catch (Throwable) {
            // Span cleanup cannot change cache behavior.
        }
    }
}
