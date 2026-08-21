<?php

declare(strict_types=1);

namespace Evolve\Module;

use Evolve\Contracts\Component\ComponentDefinition;
use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\ComponentType;
use InvalidArgumentException;

/**
 * Explicit application-supplied module definition.
 *
 * @experimental
 */
final readonly class ModuleDefinition implements ComponentDefinition
{
    public function __construct(
        private ModuleDescriptor $descriptor,
        private string $entryPointClass,
    ) {}

    public function identifier(): ComponentIdentifier
    {
        return $this->descriptor->identifier();
    }

    public function type(): ComponentType
    {
        return $this->descriptor->type();
    }

    public function graphDeclaration(): ComponentGraphDeclaration
    {
        return $this->descriptor->graphDeclaration();
    }

    public function validate(): void
    {
        (new ModuleCompatibilityValidator())->validate($this->descriptor);

        if (! class_exists($this->entryPointClass)) {
            throw new InvalidArgumentException('Module entry-point class does not exist.');
        }

        if (! is_subclass_of($this->entryPointClass, Module::class)) {
            throw new InvalidArgumentException('Module entry-point class must implement Module.');
        }
    }

    public function createEntryPoint(): ComponentEntryPoint
    {
        $entryPointClass = $this->entryPointClass;

        return new $entryPointClass();
    }
}
