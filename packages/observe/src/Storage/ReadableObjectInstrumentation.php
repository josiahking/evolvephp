<?php

declare(strict_types=1);

namespace Evolve\Observe\Storage;

use Evolve\Observe\EvolveSemanticConventions as Names;
use Evolve\Observe\MetricCardinalityPolicy;
use Evolve\Observe\OpenTelemetryComposition;
use Evolve\Storage\Contracts\Exception\StorageException;
use Evolve\Storage\Contracts\ReadableObject;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Throwable;

final class ReadableObjectInstrumentation implements ReadableObject
{
    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(
        private OpenTelemetryComposition $composition,
        private ReadableObject $reader,
        private ContextInterface $origin,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
    }

    public function read(int $maxBytes): ?string
    {
        return $this->measure('read', fn(): ?string => $this->reader->read($maxBytes));
    }

    public function close(): void
    {
        $this->measure('close', function (): void {
            $this->reader->close();
        });
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
                    ->spanBuilder(Names::SPAN_NAME_STORAGE)
                    ->setParent($this->origin)
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
