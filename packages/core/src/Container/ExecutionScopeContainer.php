<?php

declare(strict_types=1);

namespace Evolve\Core\Container;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Exception\ExecutionResetFailed;
use Evolve\Core\Exception\ExecutionScopeClosed;
use Evolve\Core\Exception\ServiceResolutionFailed;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Core\Execution\ResetCoordinator;
use Psr\Container\ContainerExceptionInterface;
use Throwable;

/**
 * @internal Concrete execution-scope resolver produced by the frozen Core container.
 */
final class ExecutionScopeContainer implements ExecutionScope
{
    private const STATE_OPEN = 'open';

    private const STATE_CLOSING = 'closing';

    private const STATE_CLOSED = 'closed';

    /**
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * @var array<string, true>
     */
    private array $resolving = [];

    private ResetCoordinator $resetCoordinator;

    private string $state = self::STATE_OPEN;

    public function __construct(private ServiceContainer $root)
    {
        $this->resetCoordinator = new ResetCoordinator();
    }

    public function get(string $id): mixed
    {
        $this->assertOpen();

        $definition = $this->root->definition($id);

        return match ($definition->lifetime()) {
            ServiceLifetime::Application => $this->root->get($id),
            ServiceLifetime::Execution => $this->getExecution($definition),
            ServiceLifetime::Transient => $this->resolve($definition),
        };
    }

    public function has(string $id): bool
    {
        return $this->root->has($id);
    }

    public function registerResetParticipant(string $id, ResetParticipant $participant): void
    {
        $this->assertOpen();
        $this->resetCoordinator->register($id, $participant);
    }

    public function close(): void
    {
        if ($this->state !== self::STATE_OPEN) {
            return;
        }

        $this->state = self::STATE_CLOSING;

        try {
            $failures = $this->resetCoordinator->reset();
        } finally {
            $this->instances = [];
            $this->resolving = [];
            $this->state = self::STATE_CLOSED;
        }

        if ($failures !== []) {
            throw new ExecutionResetFailed($failures);
        }
    }

    private function getExecution(ServiceDefinition $definition): mixed
    {
        $id = $definition->identifier();

        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        $service = $this->resolve($definition);
        $this->instances[$id] = $service;

        return $service;
    }

    private function resolve(ServiceDefinition $definition): mixed
    {
        $id = $definition->identifier();

        if (isset($this->resolving[$id])) {
            throw new ServiceResolutionFailed('Circular service dependency detected.');
        }

        $this->resolving[$id] = true;

        try {
            return $definition->create($this);
        } catch (ContainerExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ServiceResolutionFailed('Service resolution failed.', 0, $exception);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    private function assertOpen(): void
    {
        if ($this->state !== self::STATE_OPEN) {
            throw new ExecutionScopeClosed('Execution scope is closed.');
        }
    }
}
