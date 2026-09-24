<?php

declare(strict_types=1);

namespace Evolve\Observe;

use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Core\Instrumentation\ObservationSink;
use Evolve\Core\Instrumentation\ObservationType;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use Throwable;

final class ExecutionMetricsInstrumentation implements ObservationSink
{
    private const INSTRUMENTATION_NAME = 'evolvephp/observe';

    /**
     * @var array<string, array{started: int, active: bool}>
     */
    private array $executions = [];

    /**
     * @var callable(): int
     */
    private $clock;

    private ?MeterInterface $meter = null;

    private ?HistogramInterface $duration = null;

    private ?CounterInterface $count = null;

    private ?UpDownCounterInterface $active = null;

    private ?CounterInterface $failures = null;

    private ?CounterInterface $quarantines = null;

    /**
     * @param (callable(): int)|null $clock
     */
    public function __construct(private OpenTelemetryComposition $composition, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
    }

    public function observe(Observation $observation): void
    {
        if (!$this->composition->isEnabled() || $this->composition->meterProvider() === null) {
            return;
        }

        match ($observation->type()) {
            ObservationType::ExecutionStarted => $this->observeExecutionStarted($observation),
            ObservationType::ExecutionCompleted => $this->observeExecutionCompleted($observation),
            ObservationType::QuarantineRequired => $this->quarantineCounter()->add(
                1,
                MetricCardinalityPolicy::executionKindAttributes($observation->kind()),
            ),
            default => null,
        };
    }

    private function observeExecutionStarted(Observation $observation): void
    {
        $failure = null;
        $identifier = $observation->identifier()->value();
        $this->executions[$identifier] = [
            'started' => ($this->clock)(),
            'active' => false,
        ];

        try {
            $this->activeCounter()->add(1, MetricCardinalityPolicy::executionKindAttributes($observation->kind()));
            $this->executions[$identifier]['active'] = true;
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function observeExecutionCompleted(Observation $observation): void
    {
        $identifier = $observation->identifier()->value();
        $state = $this->executions[$identifier] ?? null;

        if ($state === null) {
            return;
        }

        unset($this->executions[$identifier]);

        $outcome = $observation->outcome() ?? ObservationOutcome::Succeeded;
        $outcomeAttributes = MetricCardinalityPolicy::executionOutcomeAttributes($observation->kind(), $outcome);
        $kindAttributes = MetricCardinalityPolicy::executionKindAttributes($observation->kind());
        $failure = null;

        try {
            $durationSeconds = (($this->clock)() - $state['started']) / 1_000_000_000;
            $this->durationHistogram()->record($durationSeconds, $outcomeAttributes);
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        try {
            $this->executionCounter()->add(1, $outcomeAttributes);
        } catch (Throwable $throwable) {
            $failure ??= $throwable;
        }

        if ($outcome === ObservationOutcome::Failed) {
            try {
                $this->failureCounter()->add(1, $kindAttributes);
            } catch (Throwable $throwable) {
                $failure ??= $throwable;
            }
        }

        if ($state['active']) {
            try {
                $this->activeCounter()->add(-1, $kindAttributes);
            } catch (Throwable $throwable) {
                $failure ??= $throwable;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function meter(): MeterInterface
    {
        return $this->meter ??= $this->composition->meterProvider()->getMeter(self::INSTRUMENTATION_NAME);
    }

    private function durationHistogram(): HistogramInterface
    {
        return $this->duration ??= $this->meter()->createHistogram(
            EvolveSemanticConventions::METRIC_EXECUTION_DURATION,
            's',
        );
    }

    private function executionCounter(): CounterInterface
    {
        return $this->count ??= $this->meter()->createCounter(
            EvolveSemanticConventions::METRIC_EXECUTION_COUNT,
            '{execution}',
        );
    }

    private function activeCounter(): UpDownCounterInterface
    {
        return $this->active ??= $this->meter()->createUpDownCounter(
            EvolveSemanticConventions::METRIC_EXECUTION_ACTIVE,
            '{execution}',
        );
    }

    private function failureCounter(): CounterInterface
    {
        return $this->failures ??= $this->meter()->createCounter(
            EvolveSemanticConventions::METRIC_EXECUTION_FAILURES,
            '{execution}',
        );
    }

    private function quarantineCounter(): CounterInterface
    {
        return $this->quarantines ??= $this->meter()->createCounter(
            EvolveSemanticConventions::METRIC_EXECUTION_QUARANTINES,
            '{execution}',
        );
    }
}
