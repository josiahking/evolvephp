<?php

declare(strict_types=1);

namespace Evolve\Core\Component\Registration;

use Closure;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;

/**
 * @internal Core-owned binding between one resolved declaration object and its registration callback.
 */
final class ComponentRegistration
{
    /**
     * @var Closure(ServiceDefinitionRegistrar): void
     */
    private Closure $callback;

    /**
     * @param callable(ServiceDefinitionRegistrar): void $callback
     */
    public function __construct(
        private ComponentGraphDeclaration $declaration,
        callable $callback,
    ) {
        $this->callback = Closure::fromCallable($callback);
    }

    public function declaration(): ComponentGraphDeclaration
    {
        return $this->declaration;
    }

    public function contribute(ServiceDefinitionRegistrar $registrar): void
    {
        ($this->callback)($registrar);
    }
}
