<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\PhpBench\Observe;

use Evolve\Benchmarks\Support\ObserveBenchmarkFixtureFactory;
use Evolve\Benchmarks\Support\ObserveBenchmarkFixture;
use PhpBench\Attributes as Bench;

#[Bench\Revs(50)]
#[Bench\Iterations(10)]
#[Bench\Warmup(2)]
final class ObserveOverheadBench
{
    private ObserveBenchmarkFixture $executionBare;

    private ObserveBenchmarkFixture $executionDisabled;

    private ObserveBenchmarkFixture $executionEnabled;

    private ObserveBenchmarkFixture $httpBare;

    private ObserveBenchmarkFixture $httpDisabled;

    private ObserveBenchmarkFixture $httpEnabled;

    public function setUpObserveFixtures(): void
    {
        $factory = new ObserveBenchmarkFixtureFactory();
        $this->executionBare = $factory->executionFixture('bare');
        $this->executionDisabled = $factory->executionFixture('disabled');
        $this->executionEnabled = $factory->executionFixture('enabled');
        $this->httpBare = $factory->httpFixture('bare');
        $this->httpDisabled = $factory->httpFixture('disabled');
        $this->httpEnabled = $factory->httpFixture('enabled');
    }

    #[Bench\Groups(['observe', 'execution'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchExecutionBoundaryBare(): void
    {
        $this->executionBare->invoke();
    }

    #[Bench\Groups(['observe', 'execution'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchExecutionBoundaryDisabled(): void
    {
        $this->executionDisabled->invoke();
    }

    #[Bench\Groups(['observe', 'execution'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchExecutionBoundaryEnabled(): void
    {
        $this->executionEnabled->invoke();
    }

    #[Bench\Groups(['observe', 'http'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchHttpBoundaryBare(): void
    {
        $this->httpBare->invoke();
    }

    #[Bench\Groups(['observe', 'http'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchHttpBoundaryDisabled(): void
    {
        $this->httpDisabled->invoke();
    }

    #[Bench\Groups(['observe', 'http'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchHttpBoundaryEnabled(): void
    {
        $this->httpEnabled->invoke();
    }
}
