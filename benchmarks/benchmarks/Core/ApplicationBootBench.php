<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\PhpBench\Core;

use Evolve\Benchmarks\Support\BenchmarkFixtureFactory;
use PhpBench\Attributes as Bench;

#[Bench\Revs(10)]
#[Bench\Iterations(8)]
#[Bench\Warmup(2)]
final class ApplicationBootBench
{
    #[Bench\Groups(['core', 'application-boot'])]
    #[Bench\ParamProviders(['componentCounts'])]
    public function benchOverallApplicationBoot(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::applicationBootFixture($params['components']);
        $fixture['kernel']->boot();
    }

    /**
     * @return array<string, array{components: int}>
     */
    public function componentCounts(): array
    {
        return [
            'minimal' => ['components' => 0],
            'small-graph' => ['components' => 5],
            'larger-graph' => ['components' => 50],
        ];
    }
}
