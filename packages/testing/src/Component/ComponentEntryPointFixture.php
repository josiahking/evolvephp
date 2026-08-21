<?php

declare(strict_types=1);

namespace Evolve\Testing\Component;

use Closure;
use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;

/**
 * Callback-driven component lifecycle fixture for framework and application tests.
 *
 * @experimental
 */
final readonly class ComponentEntryPointFixture implements ComponentEntryPoint
{
    /**
     * @param Closure(ServiceDefinitionRegistrar): void|null $register
     * @param Closure(ComponentBootContext): void|null $boot
     * @param Closure(): void|null $ready
     * @param Closure(): void|null $shutdown
     */
    public function __construct(
        private ?Closure $register = null,
        private ?Closure $boot = null,
        private ?Closure $ready = null,
        private ?Closure $shutdown = null,
    ) {}

    public function register(ServiceDefinitionRegistrar $registrar): void
    {
        ($this->register ?? static function (ServiceDefinitionRegistrar $registrar): void {})($registrar);
    }

    public function boot(ComponentBootContext $context): void
    {
        ($this->boot ?? static function (ComponentBootContext $context): void {})($context);
    }

    public function ready(): void
    {
        ($this->ready ?? static function (): void {})();
    }

    public function shutdown(): void
    {
        ($this->shutdown ?? static function (): void {})();
    }
}
