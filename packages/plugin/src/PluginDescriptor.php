<?php

declare(strict_types=1);

namespace Evolve\Plugin;

use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentGraphRelations;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\ComponentType;
use InvalidArgumentException;

/**
 * Immutable in-memory descriptor for an EvolvePHP framework plugin.
 *
 * @experimental
 */
final readonly class PluginDescriptor
{
    private ComponentGraphDeclaration $graphDeclaration;

    public function __construct(
        private ComponentIdentifier $identifier,
        private string $name,
        private int $evolveMajor,
        ComponentGraphRelations $graphRelations = new ComponentGraphRelations(),
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Plugin descriptor name must contain non-whitespace content.');
        }

        if ($evolveMajor < 1) {
            throw new InvalidArgumentException('Plugin descriptor EvolvePHP major must be at least 1.');
        }

        $this->graphDeclaration = new ComponentGraphDeclaration($identifier, $graphRelations);
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
        return ComponentType::Plugin;
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function evolveMajor(): int
    {
        return $this->evolveMajor;
    }

    public function graphDeclaration(): ComponentGraphDeclaration
    {
        return $this->graphDeclaration;
    }
}
