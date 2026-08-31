<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\PhpBench\Http;

use Evolve\Benchmarks\Support\BenchmarkFixtureFactory;
use Evolve\Http\HttpKernel;
use PhpBench\Attributes as Bench;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

#[Bench\Revs(50)]
#[Bench\Iterations(10)]
#[Bench\Warmup(2)]
final class HttpKernelBench
{
    private HttpKernel $kernel;

    private ServerRequestInterface $request;

    private string $scenario = 'static';

    #[Bench\BeforeMethods(['setUpKernelScenario'])]
    #[Bench\Groups(['http', 'kernel'])]
    #[Bench\ParamProviders(['kernelScenarios'])]
    public function benchHttpKernelScenario(array $params): void
    {
        $this->handlePreparedKernelRequest();
    }

    #[Bench\BeforeMethods(['setUpWarmStaticScenario'])]
    #[Bench\Groups(['http', 'kernel', 'warm'])]
    public function benchRepeatedWarmStaticRequestsThroughSameKernel(): void
    {
        // One prepared handle() call per revolution preserves the intended warm-kernel benchmark
        // semantics without re-creating the fixture inside the timed subject.
        $this->handlePreparedKernelRequest();
    }

    public function setUpKernelScenario(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::httpKernelFixture($params['scenario']);
        $this->scenario = $params['scenario'];
        $this->kernel = $fixture['kernel'];
        $this->request = $fixture['request'];
    }

    public function setUpWarmStaticScenario(): void
    {
        $fixture = BenchmarkFixtureFactory::httpKernelFixture('static');
        $this->scenario = 'static';
        $this->kernel = $fixture['kernel'];
        $this->request = $fixture['request'];
    }

    public function handlePreparedKernelRequest(): void
    {
        try {
            $this->kernel->handle($this->request);
        } catch (Throwable $exception) {
            if (!in_array($this->scenario, ['not-found', 'method-mismatch'], true)) {
                throw $exception;
            }
        }
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
