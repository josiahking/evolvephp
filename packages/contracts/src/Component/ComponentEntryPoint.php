<?php

declare(strict_types=1);

namespace Evolve\Contracts\Component;

use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;

/**
 * Component lifecycle entry point executed after descriptor validation and graph resolution.
 *
 * @experimental
 */
interface ComponentEntryPoint
{
    public function register(ServiceDefinitionRegistrar $registrar): void;

    public function boot(ComponentBootContext $context): void;

    public function ready(): void;

    public function shutdown(): void;
}
