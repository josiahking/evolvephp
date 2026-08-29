<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\PhpBench\Core;

use Evolve\Benchmarks\Support\BenchmarkFixtureFactory;
use PhpBench\Attributes as Bench;

#[Bench\Revs(1)]
#[Bench\Iterations(5)]
#[Bench\Warmup(1)]
final class PersistentSequentialExecutionBench
{
    #[Bench\Groups(['core', 'persistent-style-memory'])]
    #[Bench\ParamProviders(['iterationCounts'])]
    public function benchPersistentStyleSequentialExecutionEvidence(array $params): void
    {
        BenchmarkFixtureFactory::persistentSequentialExecutionEvidence($params['iterations'], $params['checkpoint_every']);
    }

    /**
     * @return array<string, array{iterations: int, checkpoint_every: int}>
     */
    public function iterationCounts(): array
    {
        return [
            '1000-executions' => ['iterations' => 1000, 'checkpoint_every' => 250],
            '10000-executions' => ['iterations' => 10000, 'checkpoint_every' => 2500],
        ];
    }
}
