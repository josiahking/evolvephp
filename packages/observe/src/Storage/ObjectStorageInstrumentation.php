<?php

declare(strict_types=1);

namespace Evolve\Observe\Storage;

use Evolve\Observe\EvolveSemanticConventions as Names;
use Evolve\Observe\MetricCardinalityPolicy;
use Evolve\Observe\OpenTelemetryComposition;
use Evolve\Storage\Contracts\Exception\StorageException;
use Evolve\Storage\Contracts\ObjectStorage;
use Evolve\Storage\Contracts\ReadableObject;
use Evolve\Storage\Contracts\StorageKey;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Throwable;

final class ObjectStorageInstrumentation implements ObjectStorage
{
    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(
        private OpenTelemetryComposition $composition,
        private ObjectStorage $storage,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
    }

    public function put(StorageKey $key, iterable $chunks): void
    {
        $this->measure('put', function () use ($key, $chunks): void {
            $this->storage->put($key, $chunks);
        });
    }

    public function open(StorageKey $key): ?ReadableObject
    {
        if (!$this->composition->isEnabled()) {
            return $this->storage->open($key);
        }
        try {
            $origin = Context::getCurrent();
        } catch (Throwable) {
            $origin = Context::getRoot();
        }
        $reader = $this->measure('open', fn(): ?ReadableObject => $this->storage->open($key), $origin);

        return $reader === null ? null : new ReadableObjectInstrumentation($this->composition, $reader, $origin, $this->clock);
    }

    public function delete(StorageKey $key): void
    {
        $this->measure('delete', function () use ($key): void {
            $this->storage->delete($key);
        });
    }

    /** @param callable(): mixed $delegate */
    private function measure(string $operation, callable $delegate, ?ContextInterface $parent = null): mixed
    {
        if (!$this->composition->isEnabled()) {
            return $delegate();
        }

        $span = null;
        $meter = null;
        try {
            if ($this->composition->tracerProvider() !== null) {
                $span = $this->composition->tracerProvider()->getTracer('evolvephp/observe')
                    ->spanBuilder(Names::SPAN_NAME_STORAGE)
                    ->setParent($parent ?? Context::getCurrent())
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(Names::ATTRIBUTE_STORAGE_OPERATION, $operation)
                    ->startSpan();
            }
        } catch (Throwable) {
            $this->endSpan($span);
            $span = null;
        }
        try {
            if ($this->composition->meterProvider() !== null) {
                $meter = $this->composition->meterProvider()->getMeter('evolvephp/observe');
                $meter->createHistogram(Names::METRIC_STORAGE_DURATION, 's');
                $meter->createCounter(Names::METRIC_STORAGE_COUNT, '{operation}');
                $meter->createCounter(Names::METRIC_STORAGE_FAILURES, '{operation}');
            }
        } catch (Throwable) {
            $meter = null;
        }
        $started = null;
        if ($meter !== null) {
            try {
                $started = ($this->clock)();
            } catch (Throwable) {
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
            $attributes = MetricCardinalityPolicy::storageAttributes($operation);
            if ($started !== null) {
                try {
                    $elapsed = max(0, (($this->clock)() - $started) / 1_000_000_000);
                    $meter->createHistogram(Names::METRIC_STORAGE_DURATION, 's')->record($elapsed, $attributes);
                } catch (Throwable) {
                }
            }
            try {
                $meter->createCounter(Names::METRIC_STORAGE_COUNT, '{operation}')->add(1, $attributes);
            } catch (Throwable) {
            }
            if ($failure !== null) {
                try {
                    $meter->createCounter(Names::METRIC_STORAGE_FAILURES, '{operation}')->add(1, $attributes);
                } catch (Throwable) {
                }
            }
        }

        if ($failure !== null && $span !== null) {
            try {
                $span->setAttribute(ErrorAttributes::ERROR_TYPE, $failure::class);
                if ($failure instanceof StorageException) {
                    $span->setAttribute(Names::ATTRIBUTE_STORAGE_FAILURE_CATEGORY, $failure->category()->value);
                }
                $span->setStatus(StatusCode::STATUS_ERROR);
            } catch (Throwable) {
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
        }
    }
}
