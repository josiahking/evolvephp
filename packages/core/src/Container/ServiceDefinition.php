<?php

declare(strict_types=1);

namespace Evolve\Core\Container;

use Closure;
use Psr\Container\ContainerInterface;

/**
 * @internal Core-owned service definition model; not a public Contracts API.
 */
final class ServiceDefinition
{
    private Closure $factory;

    public function __construct(
        private string $identifier,
        private ServiceLifetime $lifetime,
        callable $factory,
    ) {
        $this->factory = Closure::fromCallable($factory);
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function lifetime(): ServiceLifetime
    {
        return $this->lifetime;
    }

    public function create(ContainerInterface $container): mixed
    {
        return ($this->factory)($container);
    }
}
