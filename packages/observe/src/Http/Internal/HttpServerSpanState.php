<?php

declare(strict_types=1);

namespace Evolve\Observe\Http\Internal;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;

final class HttpServerSpanState
{
    private bool $ended = false;

    public function __construct(
        private readonly SpanInterface $span,
        private readonly string $semanticMethod,
        private readonly ?string $spanNameMethod = null,
    ) {}

    public function span(): SpanInterface
    {
        return $this->span;
    }

    public function method(): string
    {
        return $this->semanticMethod;
    }

    public function recordRouteTemplate(string $routeTemplate): void
    {
        if ($routeTemplate === '') {
            return;
        }

        $this->span->setAttribute(HttpAttributes::HTTP_ROUTE, $routeTemplate);
        $this->span->updateName($this->spanNameMethod() . ' ' . $routeTemplate);
    }

    public function end(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->span->end();
    }

    private function spanNameMethod(): string
    {
        if ($this->spanNameMethod !== null) {
            return $this->spanNameMethod;
        }

        return $this->semanticMethod === HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OTHER
            ? 'HTTP'
            : $this->semanticMethod;
    }
}
