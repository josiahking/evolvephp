<?php

declare(strict_types=1);

namespace Evolve\Module;

use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\ComponentType;
use InvalidArgumentException;

/**
 * Immutable in-memory descriptor for an EvolvePHP application module.
 *
 * @experimental
 */
final readonly class ModuleDescriptor
{
    public function __construct(
        private ComponentIdentifier $identifier,
        private string $name,
        private int $evolveMajor,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Module descriptor name must contain non-whitespace content.');
        }

        if ($evolveMajor < 1) {
            throw new InvalidArgumentException('Module descriptor EvolvePHP major must be at least 1.');
        }
    }

    public function identifier(): ComponentIdentifier
    {
        return $this->identifier;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): ComponentType
    {
        return ComponentType::Module;
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function evolveMajor(): int
    {
        return $this->evolveMajor;
    }
}
