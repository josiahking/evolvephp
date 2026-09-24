<?php

declare(strict_types=1);

namespace Evolve\Observe\Http;

use Evolve\Http\Routing\RouteMatch;
use Evolve\Observe\Http\Internal\HttpServerSpanState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class HttpRouteSpanMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $state = $request->getAttribute(HttpServerSpanState::class);
        $match = $request->getAttribute(RouteMatch::class);

        if ($state instanceof HttpServerSpanState && $match instanceof RouteMatch) {
            try {
                $state->recordRouteTemplate($match->route()->path());
            } catch (Throwable) {
                // Route span enrichment is telemetry-only; request handling must continue.
            }
        }

        return $handler->handle($request);
    }
}
