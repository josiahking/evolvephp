<?php

declare(strict_types=1);

namespace Evolve\Http\Routing;

use InvalidArgumentException;

final readonly class RouteCollection
{
    /**
     * @var list<Route>
     */
    private array $routes;

    /**
     * @param iterable<mixed> $routes
     */
    public function __construct(iterable $routes)
    {
        $orderedRoutes = [];

        foreach ($routes as $route) {
            if (!$route instanceof Route) {
                throw new InvalidArgumentException('RouteCollection entries must be instances of ' . Route::class . '.');
            }

            $orderedRoutes[] = $route;
        }

        $this->routes = $orderedRoutes;
    }

    /**
     * @return list<Route>
     */
    public function all(): array
    {
        return $this->routes;
    }
}
