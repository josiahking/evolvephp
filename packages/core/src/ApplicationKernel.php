<?php

declare(strict_types=1);

namespace Evolve\Core;

use Evolve\Contracts\Configuration\Configuration;
use Evolve\Contracts\Configuration\ConfigurationValidator;
use Evolve\Contracts\Exception\ConfigurationException;
use Evolve\Contracts\Lifecycle\ApplicationLifecycle;
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

    /**
     * @param iterable<mixed> $validators
     */
    public function __construct(
        ?Configuration $configuration = null,
        iterable $validators = [],
        ?ServiceRegistry $services = null,
    ) {
        $this->configuration = $configuration ?? new ArrayConfiguration();
        $this->services = $services;

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
        } catch (ConfigurationException $exception) {
            $this->state = ApplicationState::Failed;

            throw $exception;
        } catch (Throwable $exception) {
            $this->state = ApplicationState::Failed;

            throw new ConfigurationValidationFailed('Configuration validation failed.', 0, $exception);
        }

        try {
            $this->services?->freeze();
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

        $this->state = ApplicationState::Stopped;
    }
}
