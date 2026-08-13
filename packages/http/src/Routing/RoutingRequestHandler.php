<?php

declare(strict_types=1);

namespace Evolve\Http\Routing;

use Evolve\Http\Exception\MethodNotAllowed;
use Evolve\Http\Exception\RouteNotFound;
use Evolve\Http\Middleware\Internal\MiddlewareDispatcher;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RoutingRequestHandler implements RequestHandlerInterface
{
    /**
     * @var list<MiddlewareInterface>
     */
    private array $middleware;

    /**
     * @param iterable<mixed> $middleware
     */
    public function __construct(
        private RouteMatcher $matcher,
        iterable $middleware = [],
    ) {
        $orderedMiddleware = [];

        foreach ($middleware as $entry) {
            if (!$entry instanceof MiddlewareInterface) {
                throw new InvalidArgumentException('RoutingRequestHandler middleware entries must implement ' . MiddlewareInterface::class . '.');
            }

            $orderedMiddleware[] = $entry;
        }

        $this->middleware = $orderedMiddleware;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $match = $this->matcher->match($request);

        if ($match === null) {
            $allowedMethods = $this->matcher->allowedMethods($request->getUri()->getPath());

            if ($allowedMethods !== []) {
                throw new MethodNotAllowed($allowedMethods);
            }

            throw new RouteNotFound();
        }

        $routedRequest = $request->withAttribute(RouteMatch::class, $match);

        return (new MiddlewareDispatcher($this->middleware, $match->route()->handler()))->handle($routedRequest);
    }
}
