<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\PhpBench\Http;

use Evolve\Benchmarks\Support\BenchmarkFixtureFactory;
use PhpBench\Attributes as Bench;

#[Bench\Revs(100)]
#[Bench\Iterations(10)]
#[Bench\Warmup(2)]
final class MiddlewareBench
{
    #[Bench\Groups(['http', 'middleware'])]
    #[Bench\ParamProviders(['middlewareScenarios'])]
    public function benchMiddlewareDispatch(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::middlewareFixture($params['depth'], $params['mode']);
        $fixture['pipeline']->handle($fixture['request']);
    }

    /**
     * @return array<string, array{depth: int, mode: string}>
     */
    public function middlewareScenarios(): array
    {
        $scenarios = [];

        foreach ([0, 1, 5, 10, 20] as $depth) {
            $scenarios['pass-through-' . $depth] = ['depth' => $depth, 'mode' => 'pass-through'];

            if ($depth > 0) {
                $scenarios['early-short-circuit-' . $depth] = ['depth' => $depth, 'mode' => 'early-short-circuit'];
                $scenarios['middle-short-circuit-' . $depth] = ['depth' => $depth, 'mode' => 'middle-short-circuit'];
            }
        }

        return $scenarios;
    }
}
