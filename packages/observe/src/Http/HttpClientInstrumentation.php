<?php

declare(strict_types=1);

namespace Evolve\Observe\Http;

use Evolve\Http\Client\ClientMiddleware;
use Evolve\Observe\EvolveSemanticConventions as Names;
use Evolve\Observe\MetricCardinalityPolicy;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class HttpClientInstrumentation implements ClientMiddleware
{
    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(private OpenTelemetryComposition $composition, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
    }

    public function process(RequestInterface $request, ClientInterface $next): ResponseInterface
    {
        if (!$this->composition->isEnabled()) {
            return $next->sendRequest($request);
        }

        $span = null;
        $outbound = $request;
        try {
            $method = MetricCardinalityPolicy::httpClientAttributes($request->getMethod())[HttpAttributes::HTTP_REQUEST_METHOD];
        } catch (Throwable) {
            $method = HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER;
        }
        try {
            if ($this->composition->tracerProvider() !== null) {
                $span = $this->composition->tracerProvider()->getTracer('evolvephp/observe')
                    ->spanBuilder(Names::SPAN_NAME_HTTP_CLIENT)
                    ->setParent(Context::getCurrent())
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $method)
                    ->startSpan();
                $headers = [];
                TraceContextPropagator::getInstance()->inject(
                    $headers,
                    new class implements PropagationSetterInterface {
                        public function set(mixed &$carrier, string $key, string $value): void
                        {
                            $carrier[$key] = $value;
                        }
                    },
                    $span->storeInContext(Context::getCurrent()),
                );
                $outbound = $request->withoutHeader(TraceContextPropagator::TRACEPARENT)
                    ->withoutHeader(TraceContextPropagator::TRACESTATE);
                foreach ($headers as $name => $value) {
                    $outbound = $outbound->withHeader($name, $value);
                }
            }
        } catch (Throwable) {
            $outbound = $request;
            $this->endSpan($span);
            $span = null;
        }

        $meter = null;
        try {
            if ($this->composition->meterProvider() !== null) {
                $meter = $this->composition->meterProvider()->getMeter('evolvephp/observe');
                $meter->createHistogram(Names::METRIC_HTTP_CLIENT_DURATION, 's');
                $meter->createCounter(Names::METRIC_HTTP_CLIENT_COUNT, '{request}');
                $meter->createCounter(Names::METRIC_HTTP_CLIENT_FAILURES, '{request}');
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
        $response = null;
        try {
            $response = $next->sendRequest($outbound);
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        if ($meter !== null) {
            $attributes = MetricCardinalityPolicy::httpClientAttributes($method);
            if ($started !== null) {
                try {
                    $elapsed = max(0, (($this->clock)() - $started) / 1_000_000_000);
                    $meter->createHistogram(Names::METRIC_HTTP_CLIENT_DURATION, 's')->record($elapsed, $attributes);
                } catch (Throwable) {
                }
            }
            try {
                $meter->createCounter(Names::METRIC_HTTP_CLIENT_COUNT, '{request}')->add(1, $attributes);
            } catch (Throwable) {
            }
            if ($failure !== null) {
                try {
                    $meter->createCounter(Names::METRIC_HTTP_CLIENT_FAILURES, '{request}')->add(1, $attributes);
                } catch (Throwable) {
                }
            }
        }
        if ($span !== null) {
            try {
                if ($failure !== null) {
                    $span->setAttribute(ErrorAttributes::ERROR_TYPE, $failure::class);
                    $span->setStatus(StatusCode::STATUS_ERROR);
                } elseif ($response !== null) {
                    $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                }
            } catch (Throwable) {
            }
        }
        $this->endSpan($span);
        if ($failure !== null) {
            throw $failure;
        }

        return $response;
    }

    private function endSpan(?SpanInterface $span): void
    {
        try {
            $span?->end();
        } catch (Throwable) {
        }
    }
}
