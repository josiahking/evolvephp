<?php

declare(strict_types=1);

namespace Evolve\Contracts\Component;

/**
 * Explicit application-supplied component definition prepared before lifecycle execution.
 *
 * @experimental
 */
interface ComponentDefinition
{
    public function identifier(): ComponentIdentifier;

    public function type(): ComponentType;

    public function graphDeclaration(): ComponentGraphDeclaration;

    public function validate(): void;

    public function createEntryPoint(): ComponentEntryPoint;
}
