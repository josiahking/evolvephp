<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\PhpBench\Http;

use Evolve\Benchmarks\Support\BenchmarkFixtureFactory;
use PhpBench\Attributes as Bench;

#[Bench\Revs(100)]
#[Bench\Iterations(10)]
#[Bench\Warmup(2)]
final class RouteMatcherBench
{
    #[Bench\Groups(['http', 'routing'])]
    #[Bench\ParamProviders(['routeMatchScenarios'])]
    public function benchRouteMatching(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::routeMatchingFixture(
            $params['routes'],
            $params['category'],
            $params['position'],
        );

        $fixture['matcher']->match($fixture['request']);
    }

    #[Bench\Groups(['http', 'routing'])]
    #[Bench\ParamProviders(['routeMissScenarios'])]
    public function benchAllowedMethodsForMissAndMethodMismatch(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::routeMatchingFixture($params['routes'], $params['category']);
        $fixture['matcher']->allowedMethods($fixture['request']->getUri()->getPath());
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
