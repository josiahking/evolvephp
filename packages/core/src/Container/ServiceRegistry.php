<?php

declare(strict_types=1);

namespace Evolve\Core\Container;

use Evolve\Core\Exception\InvalidServiceDefinition;
use Evolve\Core\Exception\ServiceRegistryFrozen;
use Psr\Container\ContainerInterface;

final class ServiceRegistry
{
    /**
     * @var array<string, ServiceDefinition>
     */
    private array $definitions = [];

    private bool $frozen = false;

    private ?ContainerInterface $container = null;

    public function register(string $id, ServiceLifetime $lifetime, callable $factory): void
    {
        if ($this->frozen) {
            throw new ServiceRegistryFrozen('Service registry is frozen and cannot accept registrations.');
        }

        if ($id === '') {
            throw new InvalidServiceDefinition('Service identifier must be a non-empty string.');
        }

        if (isset($this->definitions[$id])) {
            throw new InvalidServiceDefinition('Service identifier is already registered.');
        }

        $this->definitions[$id] = new ServiceDefinition($id, $lifetime, $factory);
    }

    public function freeze(): ContainerInterface
    {
        if ($this->container !== null) {
            return $this->container;
        }

        if ($this->frozen) {
            throw new ServiceRegistryFrozen('Service registry freeze did not complete successfully.');
        }

        $this->frozen = true;

        foreach ($this->definitions as $definition) {
            if ($definition->lifetime() === ServiceLifetime::Execution) {
                throw new InvalidServiceDefinition('Execution lifetime is reserved and cannot be frozen in Phase 3.3.');
            }
        }

        $this->container = new ServiceContainer($this->definitions);

        return $this->container;
    }
}
