<?php

declare(strict_types=1);

namespace Evolve\Contracts\Component\Registration;

/**
 * Restricted component service-definition contribution boundary.
 *
 * @experimental
 */
interface ServiceDefinitionRegistrar
{
    /**
     * @param callable(\Psr\Container\ContainerInterface=): mixed $factory
     */
    public function registerApplication(string $id, callable $factory): void;

    /**
     * @param callable(\Psr\Container\ContainerInterface=): mixed $factory
     */
    public function registerExecution(string $id, callable $factory): void;

    /**
     * @param callable(\Psr\Container\ContainerInterface=): mixed $factory
     */
    public function registerTransient(string $id, callable $factory): void;
}
