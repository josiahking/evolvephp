<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\PhpBench\Core;

use Evolve\Benchmarks\Support\BenchmarkFixtureFactory;
use Evolve\Core\Execution\ExecutionScope;
use PhpBench\Attributes as Bench;
use Psr\Container\ContainerInterface;

#[Bench\Revs(100)]
#[Bench\Iterations(10)]
#[Bench\Warmup(2)]
final class ContainerResolutionBench
{
    private ContainerInterface $container;

    private ?ExecutionScope $executionScope = null;

    private string $applicationServiceId = '';

    private string $executionServiceId = '';

    private string $transientServiceId = '';

    #[Bench\BeforeMethods(['setUpApplicationFirstResolution'])]
    #[Bench\Revs(1)]
    #[Bench\Warmup(0)]
    #[Bench\Groups(['core', 'container'])]
    #[Bench\ParamProviders(['serviceCounts'])]
    public function benchApplicationFirstResolution(array $params): void
    {
        $this->container->get($this->applicationServiceId);
    }

    #[Bench\BeforeMethods(['setUpApplicationCachedResolution'])]
    #[Bench\Groups(['core', 'container'])]
    #[Bench\ParamProviders(['serviceCounts'])]
    public function benchApplicationCachedResolution(array $params): void
    {
        $this->container->get($this->applicationServiceId);
    }

    #[Bench\BeforeMethods(['setUpExecutionFirstResolution'])]
    #[Bench\AfterMethods(['tearDownExecutionScope'])]
    #[Bench\Revs(1)]
    #[Bench\Warmup(0)]
    #[Bench\Groups(['core', 'container'])]
    #[Bench\ParamProviders(['serviceCounts'])]
    public function benchExecutionFirstResolution(array $params): void
    {
        $this->executionScope->get($this->executionServiceId);
    }

    #[Bench\BeforeMethods(['setUpExecutionCachedResolution'])]
    #[Bench\AfterMethods(['tearDownExecutionScope'])]
    #[Bench\Groups(['core', 'container'])]
    #[Bench\ParamProviders(['serviceCounts'])]
    public function benchExecutionCachedResolution(array $params): void
    {
        $this->executionScope->get($this->executionServiceId);
    }

    #[Bench\BeforeMethods(['setUpTransientResolution'])]
    #[Bench\Groups(['core', 'container'])]
    #[Bench\ParamProviders(['serviceCounts'])]
    public function benchTransientRepeatedResolution(array $params): void
    {
        $this->container->get($this->transientServiceId);
    }

    public function setUpApplicationFirstResolution(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::containerFixture($params['services']);
        $this->container = $fixture['container'];
        $this->applicationServiceId = 'bench.application.' . ($params['services'] - 1);
    }

    public function setUpApplicationCachedResolution(array $params): void
    {
        $this->setUpApplicationFirstResolution($params);
        $this->container->get($this->applicationServiceId);
    }

    public function setUpExecutionFirstResolution(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::containerFixture($params['services']);
        $this->container = $fixture['container'];
        $this->executionScope = $this->container->createExecutionScope();
        $this->executionServiceId = 'bench.execution.' . ($params['services'] - 1);
    }

    public function setUpExecutionCachedResolution(array $params): void
    {
        $this->setUpExecutionFirstResolution($params);
        $this->executionScope->get($this->executionServiceId);
    }

    public function setUpTransientResolution(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::containerFixture($params['services']);
        $this->container = $fixture['container'];
        $this->transientServiceId = 'bench.transient.' . ($params['services'] - 1);
    }

    public function tearDownExecutionScope(): void
    {
        if ($this->executionScope !== null) {
            $this->executionScope->close();
            $this->executionScope = null;
        }
    }

    public function resolvePreparedApplicationCachedService(): object
    {
        return $this->container->get($this->applicationServiceId);
    }

    public function applicationServiceId(): string
    {
        return $this->applicationServiceId;
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
