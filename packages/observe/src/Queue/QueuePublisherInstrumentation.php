<?php

declare(strict_types=1);

namespace Evolve\Observe\Queue;

use Evolve\Observe\EvolveSemanticConventions as Names;
use Evolve\Observe\MetricCardinalityPolicy;
use Evolve\Observe\OpenTelemetryComposition;
use Evolve\Queue\Contracts\Exception\QueueException;
use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueuePublisher;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Throwable;

final class QueuePublisherInstrumentation implements QueuePublisher
{
    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(
        private OpenTelemetryComposition $composition,
        private QueuePublisher $publisher,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
    }

    public function publish(QueueName $queue, MessageEnvelope $message): void
    {
        if (!$this->composition->isEnabled()) {
            $this->publisher->publish($queue, $message);

            return;
        }

        $span = null;
        $outbound = $message;
        if ($this->composition->tracerProvider() !== null) {
            try {
                $span = $this->composition->tracerProvider()
                    ->getTracer('evolvephp/observe')
                    ->spanBuilder(Names::SPAN_NAME_QUEUE_PRODUCE)
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttribute(Names::ATTRIBUTE_QUEUE_ROLE, Names::QUEUE_ROLE_PRODUCER)
                    ->startSpan();
                $outbound = (new MessageEnvelopeTraceContext())->inject(
                    $message,
                    $span->storeInContext(Context::getCurrent()),
                );
            } catch (Throwable) {
                $outbound = $message;
                $this->endSpan($span);
                $span = null;
            }
        }

        $started = null;
        if ($this->composition->meterProvider() !== null) {
            try {
                $started = ($this->clock)();
            } catch (Throwable) {
                // Telemetry setup cannot prevent publication.
            }
        }

        $failure = null;
        try {
            $this->publisher->publish($queue, $outbound);
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        $this->recordMetrics($started, $failure !== null);

        if ($failure !== null && $span !== null) {
            try {
                $span->setAttribute(ErrorAttributes::ERROR_TYPE, $failure::class);
                if ($failure instanceof QueueException) {
                    $span->setAttribute(Names::ATTRIBUTE_QUEUE_FAILURE_CATEGORY, $failure->category()->value);
                }
                $span->setStatus(StatusCode::STATUS_ERROR);
            } catch (Throwable) {
                // Error telemetry cannot replace the publisher failure.
            }
        }

        $this->endSpan($span);

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function endSpan(?SpanInterface $span): void
    {
        try {
            $span?->end();
        } catch (Throwable) {
            // Span cleanup cannot change publication behavior.
        }
    }

    private function recordMetrics(?int $started, bool $failed): void
    {
        if ($started === null) {
            return;
        }

        try {
            $elapsed = max(0, (($this->clock)() - $started) / 1_000_000_000);
            $meter = $this->composition->meterProvider()->getMeter('evolvephp/observe');
            $attributes = MetricCardinalityPolicy::queueProducerAttributes();
        } catch (Throwable) {
            return;
        }

        try {
            $meter->createHistogram(Names::METRIC_QUEUE_MESSAGE_DURATION, 's')->record($elapsed, $attributes);
        } catch (Throwable) {
            // Each instrument is independent of publication.
        }
        try {
            $meter->createCounter(Names::METRIC_QUEUE_MESSAGE_COUNT, '{message}')->add(1, $attributes);
        } catch (Throwable) {
            // Each instrument is independent of publication.
        }
        if ($failed) {
            try {
                $meter->createCounter(Names::METRIC_QUEUE_MESSAGE_FAILURES, '{message}')->add(1, $attributes);
            } catch (Throwable) {
                // Each instrument is independent of publication.
            }
        }
    }
}
