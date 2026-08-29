<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\PhpBench\Http;

use Evolve\Benchmarks\Support\BenchmarkFixtureFactory;
use PhpBench\Attributes as Bench;
use Throwable;

#[Bench\Revs(50)]
#[Bench\Iterations(10)]
#[Bench\Warmup(2)]
final class HttpKernelBench
{
    #[Bench\Groups(['http', 'kernel'])]
    #[Bench\ParamProviders(['kernelScenarios'])]
    public function benchHttpKernelScenario(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::httpKernelFixture($params['scenario']);

        try {
            $fixture['kernel']->handle($fixture['request']);
        } catch (Throwable $exception) {
            if (!in_array($params['scenario'], ['not-found', 'method-mismatch'], true)) {
                throw $exception;
            }
        }
    }

    #[Bench\Groups(['http', 'kernel', 'warm'])]
    public function benchRepeatedWarmStaticRequestsThroughSameKernel(): void
    {
        $fixture = BenchmarkFixtureFactory::httpKernelFixture('static');
        $fixture['kernel']->handle($fixture['request']);
        $fixture['kernel']->handle($fixture['request']);
    }

    /**
     * @return array<string, array{scenario: string}>
     */
    public function kernelScenarios(): array
    {
        return [
            'successful-static-route' => ['scenario' => 'static'],
            'successful-parameterized-route' => ['scenario' => 'parameterized'],
            'middleware-heavy-successful-route' => ['scenario' => 'middleware-heavy'],
            '404' => ['scenario' => 'not-found'],
            '405' => ['scenario' => 'method-mismatch'],
        ];
    }
}
