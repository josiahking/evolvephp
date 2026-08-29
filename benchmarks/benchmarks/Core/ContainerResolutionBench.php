<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\PhpBench\Core;

use Evolve\Benchmarks\Support\BenchmarkFixtureFactory;
use PhpBench\Attributes as Bench;

#[Bench\Revs(100)]
#[Bench\Iterations(10)]
#[Bench\Warmup(2)]
final class ContainerResolutionBench
{
    #[Bench\Groups(['core', 'container'])]
    #[Bench\ParamProviders(['serviceCounts'])]
    public function benchApplicationFirstResolution(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::containerFixture($params['services']);
        $fixture['container']->get('bench.application.' . ($params['services'] - 1));
    }

    #[Bench\Groups(['core', 'container'])]
    #[Bench\ParamProviders(['serviceCounts'])]
    public function benchApplicationCachedResolution(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::containerFixture($params['services']);
        $id = 'bench.application.' . ($params['services'] - 1);
        $fixture['container']->get($id);
        $fixture['container']->get($id);
    }

    #[Bench\Groups(['core', 'container'])]
    #[Bench\ParamProviders(['serviceCounts'])]
    public function benchExecutionFirstResolution(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::containerFixture($params['services']);
        $scope = $fixture['container']->createExecutionScope();
        $scope->get('bench.execution.' . ($params['services'] - 1));
        $scope->close();
    }

    #[Bench\Groups(['core', 'container'])]
    #[Bench\ParamProviders(['serviceCounts'])]
    public function benchExecutionCachedResolution(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::containerFixture($params['services']);
        $scope = $fixture['container']->createExecutionScope();
        $id = 'bench.execution.' . ($params['services'] - 1);
        $scope->get($id);
        $scope->get($id);
        $scope->close();
    }

    #[Bench\Groups(['core', 'container'])]
    #[Bench\ParamProviders(['serviceCounts'])]
    public function benchTransientRepeatedResolution(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::containerFixture($params['services']);
        $fixture['container']->get('bench.transient.' . ($params['services'] - 1));
    }

    /**
     * @return array<string, array{services: int}>
     */
    public function serviceCounts(): array
    {
        return [
            '10-services' => ['services' => 10],
            '100-services' => ['services' => 100],
            '1000-services' => ['services' => 1000],
        ];
    }
}
