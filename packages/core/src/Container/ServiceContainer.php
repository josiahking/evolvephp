<?php

declare(strict_types=1);

namespace Evolve\Core\Container;

use Evolve\Core\Exception\ExecutionScopeUnavailable;
use Evolve\Core\Exception\ServiceNotFound;
use Evolve\Core\Exception\ServiceResolutionFailed;
use Evolve\Core\Execution\ExecutionScope;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * @internal Concrete PSR-11 resolver produced by ServiceRegistry::freeze().
 */
final class ServiceContainer implements ContainerInterface
{
    private const SERVICE_KEY_PREFIX = 'service:';

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
        $key = $this->serviceKey($id);

        if (! isset($this->definitions[$key])) {
            throw new ServiceNotFound('Service is not defined.');
        }

        $definition = $this->definitions[$key];

        if ($definition->lifetime() === ServiceLifetime::Execution) {
            throw new ExecutionScopeUnavailable('Execution-lifetime services require an explicit execution scope.');
        }

        if ($definition->lifetime() === ServiceLifetime::Application && array_key_exists($key, $this->instances)) {
            return $this->instances[$key];
        }

        $service = $this->resolve($definition);

        if ($definition->lifetime() === ServiceLifetime::Application) {
            $this->instances[$key] = $service;
        }

        return $service;
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$this->serviceKey($id)]);
    }

    public function createExecutionScope(): ExecutionScope
    {
        return new ExecutionScopeContainer($this);
    }

    public function definition(string $id): ServiceDefinition
    {
        $key = $this->serviceKey($id);

        if (! isset($this->definitions[$key])) {
            throw new ServiceNotFound('Service is not defined.');
        }

        return $this->definitions[$key];
    }

    private function resolve(ServiceDefinition $definition): mixed
    {
        $key = $this->serviceKey($definition->identifier());

        if (isset($this->resolving[$key])) {
            throw new ServiceResolutionFailed('Circular service dependency detected.');
        }

        $this->resolving[$key] = true;

        try {
            return $definition->create($this);
        } catch (ContainerExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ServiceResolutionFailed('Service resolution failed.', 0, $exception);
        } finally {
            unset($this->resolving[$key]);
        }
    }

    private function serviceKey(string $id): string
    {
        return self::SERVICE_KEY_PREFIX . $id;
    }
}
