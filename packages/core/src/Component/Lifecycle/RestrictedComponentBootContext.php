<?php

declare(strict_types=1);

namespace Evolve\Core\Component\Lifecycle;

use Closure;
use Evolve\Contracts\Component\ComponentBootContext;
use LogicException;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * @internal Core-owned one-component boot context.
 */
final class RestrictedComponentBootContext implements ComponentBootContext
{
    private bool $active = true;

    /**
     * @var list<Closure(): void>
     */
    private array $failureCleanup = [];

    public function __construct(private ContainerInterface $services) {}

    public function services(): ContainerInterface
    {
        $this->assertActive();

        return $this->services;
    }

    public function deferFailureCleanup(callable $cleanup): void
    {
        $this->assertActive();

        $this->failureCleanup[] = Closure::fromCallable($cleanup);
    }

    /**
     * @internal
     */
    public function closeAfterSuccessfulBoot(): void
    {
        $this->active = false;
        $this->failureCleanup = [];
    }

    /**
     * @internal
     *
     * @return list<Throwable>
     */
    public function closeAfterFailedBoot(): array
    {
        $this->active = false;
        $callbacks = array_reverse($this->failureCleanup);
        $this->failureCleanup = [];
        $failures = [];

        foreach ($callbacks as $cleanup) {
            try {
                $cleanup();
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }

        return $failures;
    }

    private function assertActive(): void
    {
        if (! $this->active) {
            throw new LogicException('Component boot context is closed.');
        }
    }
}
