<?php

declare(strict_types=1);

namespace Evolve\Core\Container;

use Evolve\Core\Exception\ServiceNotFound;
use Evolve\Core\Exception\ServiceResolutionFailed;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * @internal Concrete PSR-11 resolver produced by ServiceRegistry::freeze().
 */
final class ServiceContainer implements ContainerInterface
{
    /**
     * @var array<string, ServiceDefinition>
     */
    private array $definitions;

    /**
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * @var array<string, true>
     */
    private array $resolving = [];

    /**
     * @param array<string, ServiceDefinition> $definitions
     */
    public function __construct(array $definitions)
    {
        $this->definitions = $definitions;
    }

    public function get(string $id): mixed
    {
        if (! isset($this->definitions[$id])) {
            throw new ServiceNotFound('Service is not defined.');
        }

        $definition = $this->definitions[$id];

        if ($definition->lifetime() === ServiceLifetime::Application && array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        $service = $this->resolve($definition);

        if ($definition->lifetime() === ServiceLifetime::Application) {
            $this->instances[$id] = $service;
        }

        return $service;
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]);
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
}
