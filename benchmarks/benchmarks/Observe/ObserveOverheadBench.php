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

    /** @var array<string, ObserveBenchmarkFixture> */
    private array $dependent = [];
    public function setUpObserveFixtures(): void
    {
        $factory = new ObserveBenchmarkFixtureFactory();
        $this->executionBare = $factory->executionFixture('bare');
        $this->executionDisabled = $factory->executionFixture('disabled');
        $this->executionEnabled = $factory->executionFixture('enabled');
        $this->httpBare = $factory->httpFixture('bare');
        $this->httpDisabled = $factory->httpFixture('disabled');
        $this->httpEnabled = $factory->httpFixture('enabled');
        $this->dependent['queueJob.bare'] = $factory->queueJobFixture('bare');
        $this->dependent['queueJob.disabled'] = $factory->queueJobFixture('disabled');
        $this->dependent['queueJob.enabled'] = $factory->queueJobFixture('enabled');
        $this->dependent['database.bare'] = $factory->databaseFixture('bare');
        $this->dependent['database.disabled'] = $factory->databaseFixture('disabled');
        $this->dependent['database.enabled'] = $factory->databaseFixture('enabled');
        $this->dependent['cache.bare'] = $factory->cacheFixture('bare');
        $this->dependent['cache.disabled'] = $factory->cacheFixture('disabled');
        $this->dependent['cache.enabled'] = $factory->cacheFixture('enabled');
        $this->dependent['storage.bare'] = $factory->storageFixture('bare');
        $this->dependent['storage.disabled'] = $factory->storageFixture('disabled');
        $this->dependent['storage.enabled'] = $factory->storageFixture('enabled');
        $this->dependent['httpClient.bare'] = $factory->httpClientFixture('bare');
        $this->dependent['httpClient.disabled'] = $factory->httpClientFixture('disabled');
        $this->dependent['httpClient.enabled'] = $factory->httpClientFixture('enabled');
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
    #[Bench\Groups(['observe', 'queue-job'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchQueueJobBoundaryBare(): void
    {
        $this->dependent['queueJob.bare']->invoke();
    }

    #[Bench\Groups(['observe', 'queue-job'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchQueueJobBoundaryDisabled(): void
    {
        $this->dependent['queueJob.disabled']->invoke();
    }

    #[Bench\Groups(['observe', 'queue-job'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchQueueJobBoundaryEnabled(): void
    {
        $this->dependent['queueJob.enabled']->invoke();
    }

    #[Bench\Groups(['observe', 'database'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchDatabaseBoundaryBare(): void
    {
        $this->dependent['database.bare']->invoke();
    }

    #[Bench\Groups(['observe', 'database'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchDatabaseBoundaryDisabled(): void
    {
        $this->dependent['database.disabled']->invoke();
    }

    #[Bench\Groups(['observe', 'database'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchDatabaseBoundaryEnabled(): void
    {
        $this->dependent['database.enabled']->invoke();
    }

    #[Bench\Groups(['observe', 'cache'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchCacheBoundaryBare(): void
    {
        $this->dependent['cache.bare']->invoke();
    }

    #[Bench\Groups(['observe', 'cache'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchCacheBoundaryDisabled(): void
    {
        $this->dependent['cache.disabled']->invoke();
    }

    #[Bench\Groups(['observe', 'cache'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchCacheBoundaryEnabled(): void
    {
        $this->dependent['cache.enabled']->invoke();
    }

    #[Bench\Groups(['observe', 'storage'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchStorageBoundaryBare(): void
    {
        $this->dependent['storage.bare']->invoke();
    }

    #[Bench\Groups(['observe', 'storage'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchStorageBoundaryDisabled(): void
    {
        $this->dependent['storage.disabled']->invoke();
    }

    #[Bench\Groups(['observe', 'storage'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchStorageBoundaryEnabled(): void
    {
        $this->dependent['storage.enabled']->invoke();
    }

    #[Bench\Groups(['observe', 'http-client'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchHttpClientBoundaryBare(): void
    {
        $this->dependent['httpClient.bare']->invoke();
    }

    #[Bench\Groups(['observe', 'http-client'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchHttpClientBoundaryDisabled(): void
    {
        $this->dependent['httpClient.disabled']->invoke();
    }

    #[Bench\Groups(['observe', 'http-client'])]
    #[Bench\BeforeMethods(['setUpObserveFixtures'])]
    public function benchHttpClientBoundaryEnabled(): void
    {
        $this->dependent['httpClient.enabled']->invoke();
    }
}
