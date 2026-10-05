<?php

declare(strict_types=1);

namespace Evolve\Insight\Http;

/** @internal */
final class HttpServerDiagnosticState
{
    private ?string $routeTemplate = null;

    public function __construct(private readonly string $executionIdentifier) {}

    public function executionIdentifier(): string
    {
        return $this->executionIdentifier;
    }

    public function routeTemplate(): ?string
    {
        return $this->routeTemplate;
    }

    public function recordRouteTemplate(string $template): void
    {
        $this->routeTemplate = $template;
    }
}
