<?php

declare(strict_types=1);

namespace Evolve\Http\Routing;

use Evolve\Http\Routing\Internal\RoutePattern;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RouteMatcher
{
    /**
     * @var list<array{route: Route, pattern: RoutePattern}>
     */
    private array $compiledRoutes;

    public function __construct(RouteCollection $routes)
    {
        $compiledRoutes = [];

        foreach ($routes->all() as $route) {
            $compiledRoutes[] = [
                'route' => $route,
                'pattern' => RoutePattern::fromPath($route->path()),
            ];
        }

        $this->compiledRoutes = $compiledRoutes;
    }

    public function match(ServerRequestInterface $request): ?RouteMatch
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        foreach ($this->compiledRoutes as $entry) {
            $parameters = $entry['pattern']->match($path);

            if ($parameters === null) {
                continue;
            }

            if (!in_array($method, $entry['route']->methods(), true)) {
                continue;
            }

            return new RouteMatch($entry['route'], $parameters);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function allowedMethods(string $path): array
    {
        $methods = [];
        $seen = [];

        foreach ($this->compiledRoutes as $entry) {
            if ($entry['pattern']->match($path) === null) {
                continue;
            }

            foreach ($entry['route']->methods() as $method) {
                if (isset($seen[$method])) {
                    continue;
                }

                $seen[$method] = true;
                $methods[] = $method;
            }
        }

        return $methods;
    }
}
