<?php

declare(strict_types=1);

namespace Evolve\Core;

use Evolve\Contracts\Configuration\Configuration;
use Evolve\Contracts\Configuration\ConfigurationValidator;
use Evolve\Contracts\Exception\ConfigurationException;
use Evolve\Contracts\Lifecycle\ApplicationLifecycle;
use Evolve\Core\Component\ComponentBootstrapper;
use Evolve\Core\Component\Lifecycle\ComponentLifecycleCoordinator;
use Evolve\Core\Configuration\ArrayConfiguration;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ConfigurationValidationFailed;
use Evolve\Core\Exception\InvalidLifecycleTransition;
use Evolve\Core\Lifecycle\ApplicationState;
use InvalidArgumentException;
use Throwable;

final class ApplicationKernel implements ApplicationLifecycle
{
    private ApplicationState $state = ApplicationState::Created;

    private Configuration $configuration;

    /**
     * @var list<ConfigurationValidator>
     */
    private array $validators = [];

    private ?ServiceRegistry $services;

    private ?ComponentLifecycleCoordinator $components = null;

    private ?ComponentBootstrapper $componentBootstrapper = null;

    /**
     * @param iterable<mixed> $validators
     */
    public function __construct(
        ?Configuration $configuration = null,
        iterable $validators = [],
        ?ServiceRegistry $services = null,
        ComponentLifecycleCoordinator|ComponentBootstrapper|null $components = null,
    ) {
        $this->configuration = $configuration ?? new ArrayConfiguration();
        $this->services = $services;

        if ($components instanceof ComponentBootstrapper) {
            $this->componentBootstrapper = $components;
        } else {
            $this->components = $components;
        }

        foreach ($validators as $validator) {
            if (! $validator instanceof ConfigurationValidator) {
                throw new InvalidArgumentException('Application kernel validators must implement ConfigurationValidator.');
            }

            $this->validators[] = $validator;
        }
    }

    public function boot(): void
    {
        if ($this->state !== ApplicationState::Created) {
            throw new InvalidLifecycleTransition('Application kernel cannot boot from the current lifecycle state.');
        }

        $this->state = ApplicationState::Booting;

        try {
            foreach ($this->validators as $validator) {
                $validator->validate($this->configuration);
            }

            $this->componentBootstrapper?->validateConfiguration($this->configuration);
        } catch (ConfigurationException $exception) {
            $this->state = ApplicationState::Failed;

            throw $exception;
        } catch (Throwable $exception) {
            $this->state = ApplicationState::Failed;

            throw new ConfigurationValidationFailed('Configuration validation failed.', 0, $exception);
        }

        try {
            if ($this->componentBootstrapper !== null) {
                $this->components = $this->componentBootstrapper->prepare($this->configuration);
            }

            if ($this->components !== null && $this->services === null) {
                $this->services = new ServiceRegistry();
            }

            if ($this->components !== null) {
                $registry = $this->services;

                if ($registry === null) {
                    throw new InvalidLifecycleTransition('Component lifecycle registration requires a service registry.');
                }

                $this->components->register($registry);
            }

            $services = $this->services?->freeze();

            if ($this->components !== null && $services !== null) {
                $this->components->boot($services);
                $this->components->ready();
            }
        } catch (Throwable $exception) {
            $this->state = ApplicationState::Failed;

            throw $exception;
        }

        $this->state = ApplicationState::Ready;
    }

    public function shutdown(): void
    {
        if ($this->state !== ApplicationState::Ready) {
            throw new InvalidLifecycleTransition('Application kernel cannot shut down from the current lifecycle state.');
        }

        try {
            $this->components?->shutdown();
        } catch (Throwable $exception) {
            $this->state = ApplicationState::Stopped;

            throw $exception;
        }

        $this->state = ApplicationState::Stopped;
    }
}
