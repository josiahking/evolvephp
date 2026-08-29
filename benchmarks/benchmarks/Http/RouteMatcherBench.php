<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\PhpBench\Http;

use Evolve\Benchmarks\Support\BenchmarkFixtureFactory;
use Evolve\Http\Routing\RouteMatch;
use Evolve\Http\Routing\RouteMatcher;
use PhpBench\Attributes as Bench;
use Psr\Http\Message\ServerRequestInterface;

#[Bench\Revs(100)]
#[Bench\Iterations(10)]
#[Bench\Warmup(2)]
final class RouteMatcherBench
{
    private RouteMatcher $matcher;

    private ServerRequestInterface $request;

    private string $allowedMethodsPath = '';

    #[Bench\BeforeMethods(['setUpRouteScenario'])]
    #[Bench\Groups(['http', 'routing'])]
    #[Bench\ParamProviders(['routeMatchScenarios'])]
    public function benchRouteMatching(array $params): void
    {
        $match = $this->matcher->match($this->request);
        $match?->route()->path();
    }

    #[Bench\BeforeMethods(['setUpAllowedMethodsScenario'])]
    #[Bench\Groups(['http', 'routing'])]
    #[Bench\ParamProviders(['routeMissScenarios'])]
    public function benchAllowedMethodsForMissAndMethodMismatch(array $params): void
    {
        $this->matcher->allowedMethods($this->allowedMethodsPath);
    }

    public function setUpRouteScenario(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::routeMatchingFixture(
            $params['routes'],
            $params['category'],
            $params['position'],
        );

        $this->matcher = $fixture['matcher'];
        $this->request = $fixture['request'];
    }

    public function setUpAllowedMethodsScenario(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::routeMatchingFixture($params['routes'], $params['category']);
        $this->matcher = $fixture['matcher'];
        $this->request = $fixture['request'];
        $this->allowedMethodsPath = $this->request->getUri()->getPath();
    }

    public function matchPreparedRoute(): ?RouteMatch
    {
        return $this->matcher->match($this->request);
    }

    /**
     * @return array<string, array{routes: int, category: string, position: string}>
     */
    public function routeMatchScenarios(): array
    {
        $scenarios = [];

        foreach ([10, 100, 1000] as $routes) {
            foreach (['first', 'middle', 'last'] as $position) {
                $scenarios['static-' . $routes . '-' . $position] = [
                    'routes' => $routes,
                    'category' => 'static',
                    'position' => $position,
                ];
                $scenarios['parameterized-' . $routes . '-' . $position] = [
                    'routes' => $routes,
                    'category' => 'parameterized',
                    'position' => $position,
                ];
            }
        }

        return $scenarios;
    }

    /**
     * @return array<string, array{routes: int, category: string}>
     */
    public function routeMissScenarios(): array
    {
        $scenarios = [];

        foreach ([10, 100, 1000] as $routes) {
            $scenarios['miss-' . $routes] = ['routes' => $routes, 'category' => 'miss'];
            $scenarios['method-mismatch-' . $routes] = ['routes' => $routes, 'category' => 'method-mismatch'];
        }

        return $scenarios;
    }
}
