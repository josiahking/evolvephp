<?php

declare(strict_types=1);

namespace Evolve\Contracts\Lifecycle;

interface ApplicationLifecycle
{
    public function boot(): void;

    public function shutdown(): void;
}
