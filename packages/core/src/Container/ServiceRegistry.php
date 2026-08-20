<?php

declare(strict_types=1);

namespace Evolve\Core\Container;

use Evolve\Core\Exception\ExecutionScopeUnavailable;
use Evolve\Core\Exception\InvalidServiceDefinition;
use Evolve\Core\Exception\ServiceRegistryFrozen;
use Evolve\Core\Execution\ExecutionScope;
use Psr\Container\ContainerInterface;

final class ServiceRegistry
{
    private const SERVICE_KEY_PREFIX = 'service:';

    /**
     * @var array<string, ServiceDefinition>
     */
    private array $definitions = [];

    private bool $frozen = false;

    private ?ServiceContainer $container = null;

    public function register(string $id, ServiceLifetime $lifetime, callable $factory): void
    {
        $this->assertMutable();

        if ($id === '') {
            throw new InvalidServiceDefinition('Service identifier must be a non-empty string.');
        }

        $key = $this->serviceKey($id);

        if (isset($this->definitions[$key])) {
            throw new InvalidServiceDefinition('Service identifier is already registered.');
        }

        $this->definitions[$key] = new ServiceDefinition($id, $lifetime, $factory);
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

        $this->container = new ServiceContainer($this->definitions);

        return $this->container;
    }

    public function createExecutionScope(): ExecutionScope
    {
        if ($this->container === null) {
            throw new ExecutionScopeUnavailable('Execution scope creation requires a successful explicit freeze first.');
        }

        return $this->container->createExecutionScope();
    }

    /**
     * @internal
     */
    public function assertMutable(): void
    {
        if ($this->frozen) {
            throw new ServiceRegistryFrozen('Service registry is frozen and cannot accept registrations.');
        }
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public function definitionIdentifiers(): array
    {
        return array_map(
            static fn(ServiceDefinition $definition): string => $definition->identifier(),
            array_values($this->definitions),
        );
    }

    /**
     * @internal
     *
     * @param array<mixed> $definitions
     */
    public function assertCanPublishDefinitions(array $definitions): void
    {
        $this->assertMutable();

        $seen = [];

        foreach ($definitions as $definition) {
            if (!$definition instanceof ServiceDefinition) {
                throw new InvalidServiceDefinition('Service publication batch must contain service definitions.');
            }

            $id = $definition->identifier();

            if ($id === '') {
                throw new InvalidServiceDefinition('Service identifier must be a non-empty string.');
            }

            $key = $this->serviceKey($id);

            if (isset($this->definitions[$key]) || isset($seen[$key])) {
                throw new InvalidServiceDefinition('Service identifier is already registered.');
            }

            $seen[$key] = true;
        }
    }

    /**
     * @internal
     *
     * @param list<ServiceDefinition> $definitions
     */
    public function publishDefinitions(array $definitions): void
    {
        $this->assertCanPublishDefinitions($definitions);

        $published = [];

        foreach ($definitions as $definition) {
            $published[$this->serviceKey($definition->identifier())] = $definition;
        }

        $this->definitions += $published;
    }

    private function serviceKey(string $id): string
    {
        return self::SERVICE_KEY_PREFIX . $id;
    }
}
