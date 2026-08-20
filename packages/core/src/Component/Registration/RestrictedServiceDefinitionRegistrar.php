<?php

declare(strict_types=1);

namespace Evolve\Core\Component\Registration;

use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Core\Container\ServiceLifetime;
use LogicException;

/**
 * @internal Active-only restricted registrar exposed to component registration callbacks.
 */
final class RestrictedServiceDefinitionRegistrar implements ServiceDefinitionRegistrar
{
    private ?ServiceDefinitionContributionBuffer $buffer;

    public function __construct(ServiceDefinitionContributionBuffer $buffer)
    {
        $this->buffer = $buffer;
    }

    public function registerApplication(string $id, callable $factory): void
    {
        $this->stage($id, ServiceLifetime::Application, $factory);
    }

    public function registerExecution(string $id, callable $factory): void
    {
        $this->stage($id, ServiceLifetime::Execution, $factory);
    }

    public function registerTransient(string $id, callable $factory): void
    {
        $this->stage($id, ServiceLifetime::Transient, $factory);
    }

    public function close(): void
    {
        $this->buffer = null;
    }

    private function stage(string $id, ServiceLifetime $lifetime, callable $factory): void
    {
        if ($this->buffer === null) {
            throw new LogicException('Service definition registrar is no longer active.');
        }

        $this->buffer->stage($id, $lifetime, $factory);
    }
}
