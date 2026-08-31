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

        $candidateSegments = null;
        $segmentsParsed = false;

        foreach ($this->compiledRoutes as $entry) {
            $pattern = $entry['pattern'];

            if ($pattern->isStatic()) {
                $parameters = $pattern->match($path);
            } else {
                if (!$segmentsParsed) {
                    $candidateSegments = $this->parseCandidate($path);
                    $segmentsParsed = true;
                }

                $parameters = $pattern->matchSegments($candidateSegments);
            }

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
    private function parseCandidate(string $path): array
    {
        if ($path === '') {
            return [];
        }

        if ($path[0] !== '/') {
            return [];
        }

        return $path === '/' ? [] : explode('/', substr($path, 1));
    }

    /**
     * @return list<string>
     */
    public function allowedMethods(string $path): array
    {
        $methods = [];
        $seen = [];
        $candidateSegments = null;
        $segmentsParsed = false;

        foreach ($this->compiledRoutes as $entry) {
            $pattern = $entry['pattern'];

            if ($pattern->isStatic()) {
                $matches = $pattern->match($path) !== null;
            } else {
                if (!$segmentsParsed) {
                    $candidateSegments = $this->parseCandidate($path);
                    $segmentsParsed = true;
                }

                $matches = $pattern->matchSegments($candidateSegments) !== null;
            }

            if (!$matches) {
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
