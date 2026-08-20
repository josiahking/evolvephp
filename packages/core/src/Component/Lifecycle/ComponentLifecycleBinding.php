<?php

declare(strict_types=1);

namespace Evolve\Core\Component\Lifecycle;

use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Contracts\Component\ComponentGraphDeclaration;

/**
 * @internal Core-owned binding between one resolved declaration object and one lifecycle entry point.
 */
final readonly class ComponentLifecycleBinding
{
    public function __construct(
        private ComponentGraphDeclaration $declaration,
        private ComponentEntryPoint $entryPoint,
    ) {}

    public function declaration(): ComponentGraphDeclaration
    {
        return $this->declaration;
    }

    public function entryPoint(): ComponentEntryPoint
    {
        return $this->entryPoint;
    }
}
