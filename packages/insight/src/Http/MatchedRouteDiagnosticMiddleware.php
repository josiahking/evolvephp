<?php

declare(strict_types=1);

namespace Evolve\Insight\Http;

use Evolve\Http\Routing\RouteMatch;
use Evolve\Insight\Capture\DiagnosticAttribute;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MatchedRouteDiagnosticMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $state = $request->getAttribute(HttpServerDiagnosticState::class);
            $match = $request->getAttribute(RouteMatch::class);

            if ($state instanceof HttpServerDiagnosticState && $match instanceof RouteMatch) {
                $template = $match->route()->path();
                if (strlen($template) <= DiagnosticAttribute::MAX_STRING_VALUE_LENGTH) {
                    $state->recordRouteTemplate($template);
                }
            }
        } catch (\Throwable) {
            // Route diagnostics must not affect the application handler.
        }

        return $handler->handle($request);
    }
}
