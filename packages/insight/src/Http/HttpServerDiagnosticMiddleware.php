<?php

declare(strict_types=1);

namespace Evolve\Insight\Http;

use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\Capture\DiagnosticEntrySink;
use Evolve\Insight\Infrastructure\ExecutionCorrelation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HttpServerDiagnosticMiddleware implements MiddlewareInterface
{
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(
        private DiagnosticEntrySink $sink,
        private ExecutionCorrelation $correlation,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null ? static fn(): int => hrtime(true) : \Closure::fromCallable($clock);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $identifier = $this->correlation->identifier();
        } catch (\Throwable) {
            $identifier = null;
        }

        if ($identifier === null) {
            return $handler->handle($request);
        }

        try {
            $existing = $request->getAttribute(HttpServerDiagnosticState::class);
            $duplicate = $existing instanceof HttpServerDiagnosticState && $existing->executionIdentifier() === $identifier;
            if (!$duplicate) {
                $state = new HttpServerDiagnosticState($identifier);
                $diagnosticRequest = $request->withAttribute(HttpServerDiagnosticState::class, $state);
            }
        } catch (\Throwable) {
            return $handler->handle($request);
        }

        if ($duplicate) {
            return $handler->handle($request);
        }

        $start = $this->time();
        try {
            $response = $handler->handle($diagnosticRequest);
        } catch (\Throwable $failure) {
            $this->record($identifier, $diagnosticRequest, $state, null, $failure, $start);
            throw $failure;
        }

        $this->record($identifier, $diagnosticRequest, $state, $response, null, $start);
        return $response;
    }

    private function time(): ?int
    {
        try {
            return ($this->clock)();
        } catch (\Throwable) {
            return null;
        }
    }

    private function record(
        string $identifier,
        ServerRequestInterface $request,
        HttpServerDiagnosticState $state,
        ?ResponseInterface $response,
        ?\Throwable $failure,
        ?int $start,
    ): void {
        try {
            $attributes = [];
            $method = $request->getMethod();
            if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_\x60|~-]{1,32}$/D', $method) === 1) {
                $attributes[] = new DiagnosticAttribute('method', DiagnosticDataClassification::PublicOperationalMetadata, $method);
            }
            if ($state->routeTemplate() !== null) {
                $attributes[] = new DiagnosticAttribute('route_template', DiagnosticDataClassification::InternalOperationalMetadata, $state->routeTemplate());
            }
            if ($response !== null) {
                $status = $response->getStatusCode();
                if ($status >= 100 && $status <= 599) {
                    $attributes[] = new DiagnosticAttribute('status_code', DiagnosticDataClassification::PublicOperationalMetadata, $status);
                }
            }
            $attributes[] = new DiagnosticAttribute('outcome', DiagnosticDataClassification::PublicOperationalMetadata, $failure === null ? 'success' : 'failure');
            $end = $this->time();
            if ($start !== null && $end !== null) {
                $attributes[] = new DiagnosticAttribute('duration_ns', DiagnosticDataClassification::PublicOperationalMetadata, max(0, $end - $start));
            }
            if ($failure !== null && strlen($failure::class) <= DiagnosticAttribute::MAX_STRING_VALUE_LENGTH) {
                $attributes[] = new DiagnosticAttribute('error_type', DiagnosticDataClassification::InternalOperationalMetadata, $failure::class);
            }
            $this->sink->capture(new DiagnosticEntry($identifier, 'evolve.http.server', 'request', $attributes));
        } catch (\Throwable) {
            // Diagnostic capture is secondary to the HTTP operation.
        }
    }
}
