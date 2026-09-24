<?php

declare(strict_types=1);

namespace Evolve\Observe\Http;

use Evolve\Observe\EvolveSemanticConventions;
use Evolve\Observe\Http\Internal\HttpServerMetricState;
use Evolve\Observe\MetricCardinalityPolicy;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class HttpServerMetricsInstrumentation
{
    private const INSTRUMENTATION_NAME = 'evolvephp/observe';

    /**
     * @var callable(): int
     */
    private $clock;

    private ?MeterInterface $meter = null;

    private ?HistogramInterface $duration = null;

    private ?CounterInterface $count = null;

    private ?UpDownCounterInterface $active = null;

    private ?CounterInterface $failures = null;

    /**
     * @param (callable(): int)|null $clock
     */
    public function __construct(private OpenTelemetryComposition $composition, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
    }

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $operation
     */
    public function measure(ServerRequestInterface $request, callable $operation): ResponseInterface
    {
        if (!$this->composition->isEnabled() || $this->composition->meterProvider() === null) {
            return $operation($request);
        }

        if ($request->getAttribute(HttpServerMetricState::class) instanceof HttpServerMetricState) {
            return $operation($request);
        }

        try {
            $state = new HttpServerMetricState(
                ($this->clock)(),
                false,
                MetricCardinalityPolicy::httpServerAttributes($request->getMethod()),
            );
            $request = $request->withAttribute(HttpServerMetricState::class, $state);
        } catch (Throwable) {
            return $operation($request);
        }

        try {
            $this->activeCounter()->add(1, $state->attributes());
            $state->markActiveIncremented();
        } catch (Throwable) {
            // Metrics are telemetry only; the request lifecycle continues.
        }

        $response = null;
        $applicationThrowable = null;

        try {
            $response = $operation($request);
        } catch (Throwable $throwable) {
            $applicationThrowable = $throwable;
        }

        if ($response !== null) {
            $this->recordResponse($state, $response);
        }

        if ($applicationThrowable !== null) {
            $this->recordThrowable($state);
        }

        $this->finish($state);

        if ($applicationThrowable !== null) {
            throw $applicationThrowable;
        }

        return $response;
    }

    private function recordResponse(HttpServerMetricState $state, ResponseInterface $response): void
    {
        try {
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 500 && $statusCode <= 599) {
                $this->failureCounter()->add(1, $state->attributes());
            }
        } catch (Throwable) {
            // Failure metrics must not replace the response.
        }
    }

    private function recordThrowable(HttpServerMetricState $state): void
    {
        try {
            $this->failureCounter()->add(1, $state->attributes());
        } catch (Throwable) {
            // Failure metrics must not replace the original application throwable.
        }
    }

    private function finish(HttpServerMetricState $state): void
    {
        try {
            $this->durationHistogram()->record((($this->clock)() - $state->started()) / 1_000_000_000, $state->attributes());
        } catch (Throwable) {
            // Duration metrics must not replace the response or throwable.
        }

        try {
            $this->requestCounter()->add(1, $state->attributes());
        } catch (Throwable) {
            // Count metrics must not replace the response or throwable.
        }

        if (!$state->activeIncremented()) {
            return;
        }

        try {
            $this->activeCounter()->add(-1, $state->attributes());
        } catch (Throwable) {
            // Cleanup metrics must not replace the response or throwable.
        }
    }

    private function meter(): MeterInterface
    {
        return $this->meter ??= $this->composition->meterProvider()->getMeter(self::INSTRUMENTATION_NAME);
    }

    private function durationHistogram(): HistogramInterface
    {
        return $this->duration ??= $this->meter()->createHistogram(
            HttpMetrics::HTTP_SERVER_REQUEST_DURATION,
            's',
        );
    }

    private function requestCounter(): CounterInterface
    {
        return $this->count ??= $this->meter()->createCounter(
            EvolveSemanticConventions::METRIC_HTTP_SERVER_REQUEST_COUNT,
            '{request}',
        );
    }

    private function activeCounter(): UpDownCounterInterface
    {
        return $this->active ??= $this->meter()->createUpDownCounter(
            EvolveSemanticConventions::METRIC_HTTP_SERVER_ACTIVE_REQUESTS,
            '{request}',
        );
    }

    private function failureCounter(): CounterInterface
    {
        return $this->failures ??= $this->meter()->createCounter(
            EvolveSemanticConventions::METRIC_HTTP_SERVER_REQUEST_FAILURES,
            '{request}',
        );
    }
}
