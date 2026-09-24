<?php

declare(strict_types=1);

namespace Evolve\Observe\Http;

use Evolve\Observe\Exception\OpenTelemetryContextDetachFailed;
use Evolve\Observe\Http\Internal\HttpServerSpanState;
use Evolve\Observe\Http\Internal\PsrServerRequestPropagationGetter;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final readonly class HttpServerTraceInstrumentation
{
    private const INSTRUMENTATION_NAME = 'evolvephp/observe';

    /**
     * @var array<string, true>
     */
    private const KNOWN_METHODS = [
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_CONNECT => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_DELETE => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_GET => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_HEAD => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OPTIONS => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_PATCH => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_POST => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_PUT => true,
        HttpAttributes::HTTP_REQUEST_METHOD_VALUE_TRACE => true,
    ];

    public function __construct(private OpenTelemetryComposition $composition) {}

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $operation
     */
    public function trace(ServerRequestInterface $request, callable $operation): ResponseInterface
    {
        if (!$this->composition->isEnabled() || $this->composition->tracerProvider() === null) {
            return $operation($request);
        }

        if ($request->getAttribute(HttpServerSpanState::class) instanceof HttpServerSpanState) {
            return $operation($request);
        }

        try {
            [$scope, $state, $request] = $this->startServerSpan($request);
        } catch (Throwable) {
            return $operation($request);
        }

        $response = null;
        $applicationThrowable = null;
        $cleanupThrowable = null;

        try {
            $response = $operation($request);
        } catch (Throwable $throwable) {
            $applicationThrowable = $throwable;
        }

        if ($response !== null) {
            $this->recordResponse($state, $response);
        }

        if ($applicationThrowable !== null) {
            $this->recordThrowable($state, $applicationThrowable);
        }

        try {
            $state->end();
        } catch (Throwable) {
            // Span ending is telemetry cleanup only; context detachment still must run.
        }

        try {
            $detachStatus = $scope->detach();

            if ($detachStatus !== 0) {
                $cleanupThrowable = new OpenTelemetryContextDetachFailed($detachStatus);
            }
        } catch (Throwable $throwable) {
            $cleanupThrowable = $throwable;
        }

        if ($cleanupThrowable !== null) {
            throw $cleanupThrowable;
        }

        if ($applicationThrowable !== null) {
            throw $applicationThrowable;
        }

        return $response;
    }

    /**
     * @return array{0: ScopeInterface, 1: HttpServerSpanState, 2: ServerRequestInterface}
     */
    private function startServerSpan(ServerRequestInterface $request): array
    {
        [$semanticMethod, $spanNameMethod, $originalMethod] = $this->methodSemantics($request->getMethod());
        $uri = $request->getUri();
        $attributes = [
            HttpAttributes::HTTP_REQUEST_METHOD => $semanticMethod,
            UrlAttributes::URL_PATH => $uri->getPath(),
        ];

        if ($originalMethod !== null) {
            $attributes[HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL] = $originalMethod;
        }

        if ($uri->getScheme() !== '') {
            $attributes[UrlAttributes::URL_SCHEME] = $uri->getScheme();
        }

        $parent = TraceContextPropagator::getInstance()->extract(
            $request,
            PsrServerRequestPropagationGetter::getInstance(),
            Context::getRoot(),
        );

        $span = $this->composition
            ->tracerProvider()
            ->getTracer(self::INSTRUMENTATION_NAME)
            ->spanBuilder($spanNameMethod)
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttributes($attributes)
            ->startSpan();
        $state = new HttpServerSpanState($span, $semanticMethod, $spanNameMethod);

        try {
            $instrumentedRequest = $request->withAttribute(HttpServerSpanState::class, $state);
        } catch (Throwable $throwable) {
            try {
                $state->end();
            } catch (Throwable) {
                // State attachment failed before activation; cleanup failure is telemetry-only.
            }

            throw $throwable;
        }

        try {
            $scope = $span->activate();
        } catch (Throwable $throwable) {
            try {
                $state->end();
            } catch (Throwable) {
                // Activation failed before user code; cleanup failure is telemetry-only.
            }

            throw $throwable;
        }

        return [$scope, $state, $instrumentedRequest];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function methodSemantics(string $method): array
    {
        $uppercase = strtoupper($method);

        if (isset(self::KNOWN_METHODS[$uppercase])) {
            return [
                $uppercase,
                $uppercase,
                $method === $uppercase ? null : $method,
            ];
        }

        return [
            HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER,
            'HTTP',
            $method === '' ? null : $method,
        ];
    }

    private function recordResponse(HttpServerSpanState $state, ResponseInterface $response): void
    {
        try {
            $statusCode = $response->getStatusCode();
            $span = $state->span();

            $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);

            if ($statusCode < 500) {
                return;
            }

            $span->setAttribute(ErrorAttributes::ERROR_TYPE, (string) $statusCode);
            $span->setStatus(StatusCode::STATUS_ERROR);
        } catch (Throwable) {
            // Response telemetry must not replace the caller-owned response.
        }
    }

    private function recordThrowable(HttpServerSpanState $state, Throwable $throwable): void
    {
        try {
            $state->span()
                ->setAttribute(ErrorAttributes::ERROR_TYPE, $throwable::class)
                ->setStatus(StatusCode::STATUS_ERROR);
        } catch (Throwable) {
            // Error telemetry must not replace the original application throwable.
        }
    }
}
