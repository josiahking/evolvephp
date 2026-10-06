<?php

declare(strict_types=1);

namespace Evolve\Observe\Queue;

use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Job\JobExecutionContext;
use Evolve\Observe\EvolveSemanticConventions as Names;
use Evolve\Observe\Exception\OpenTelemetryContextDetachFailed;
use Evolve\Observe\MetricCardinalityPolicy;
use Evolve\Observe\OpenTelemetryComposition;
use Evolve\Queue\Contracts\MessageEnvelope;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Throwable;

final class JobExecutionContextInstrumentation implements JobExecutionContext
{
    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(
        private OpenTelemetryComposition $composition,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
    }

    public function run(MessageEnvelope $message, callable $execution): ExecutionOutcome
    {
        if (!$this->composition->isEnabled()) {
            return $execution();
        }

        $span = null;
        $scope = null;
        if ($this->composition->tracerProvider() !== null) {
            try {
                $parent = (new MessageEnvelopeTraceContext())->extract($message);
                $span = $this->composition->tracerProvider()
                    ->getTracer('evolvephp/observe')
                    ->spanBuilder(Names::SPAN_NAME_QUEUE_CONSUME)
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_CONSUMER)
                    ->setAttribute(Names::ATTRIBUTE_QUEUE_ROLE, Names::QUEUE_ROLE_CONSUMER)
                    ->startSpan();
                $scope = $span->activate();
            } catch (Throwable) {
                $this->endSpan($span);
                $span = null;
            }
        }

        $started = null;
        if ($this->composition->meterProvider() !== null) {
            try {
                $started = ($this->clock)();
            } catch (Throwable) {
                // Metrics cannot prevent Core execution.
            }
        }

        $outcome = null;
        $failure = null;
        try {
            $outcome = $execution();
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        $failed = $failure !== null || $outcome->primaryFailed();
        $this->recordMetrics($started, $failed);
        if ($span !== null) {
            $this->recordSpanOutcome($span, $outcome, $failure);
            $this->endSpan($span);
        }

        // A failed detach takes precedence over Core's failure: the Job runner must quarantine.
        if ($scope !== null) {
            $status = $scope->detach();
            if ($status !== 0) {
                throw new OpenTelemetryContextDetachFailed($status);
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $outcome;
    }

    private function recordSpanOutcome(SpanInterface $span, ?ExecutionOutcome $outcome, ?Throwable $failure): void
    {
        $error = $failure ?? $outcome?->primaryThrowable() ?? $outcome?->cleanupThrowable();
        if ($error === null) {
            return;
        }

        try {
            $span->setAttribute(ErrorAttributes::ERROR_TYPE, $error::class);
            $span->setStatus(StatusCode::STATUS_ERROR);
        } catch (Throwable) {
            // Error recording cannot change Core behavior.
        }
    }

    private function endSpan(?SpanInterface $span): void
    {
        try {
            $span?->end();
        } catch (Throwable) {
            // Span cleanup cannot change Core behavior.
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
            $attributes = MetricCardinalityPolicy::queueConsumerAttributes();
        } catch (Throwable) {
            return;
        }

        try {
            $meter->createHistogram(Names::METRIC_QUEUE_MESSAGE_DURATION, 's')->record($elapsed, $attributes);
        } catch (Throwable) {
            // Each instrument is independent of Core.
        }
        try {
            $meter->createCounter(Names::METRIC_QUEUE_MESSAGE_COUNT, '{message}')->add(1, $attributes);
        } catch (Throwable) {
            // Each instrument is independent of Core.
        }
        if ($failed) {
            try {
                $meter->createCounter(Names::METRIC_QUEUE_MESSAGE_FAILURES, '{message}')->add(1, $attributes);
            } catch (Throwable) {
                // Each instrument is independent of Core.
            }
        }
    }
}
