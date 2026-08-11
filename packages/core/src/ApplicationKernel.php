<?php

declare(strict_types=1);

namespace Evolve\Core;

use Evolve\Contracts\Lifecycle\ApplicationLifecycle;
use Evolve\Core\Exception\InvalidLifecycleTransition;
use Evolve\Core\Lifecycle\ApplicationState;

final class ApplicationKernel implements ApplicationLifecycle
{
    private ApplicationState $state = ApplicationState::Created;

    public function boot(): void
    {
        if ($this->state !== ApplicationState::Created) {
            throw new InvalidLifecycleTransition('Application kernel cannot boot from the current lifecycle state.');
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
