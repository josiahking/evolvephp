<?php

declare(strict_types=1);

namespace Evolve\Contracts\Component;

use InvalidArgumentException;

/**
 * Narrow immutable declaration of one component's graph relations.
 *
 * @experimental
 */
final readonly class ComponentGraphDeclaration
{
    public function __construct(
        private ComponentIdentifier $identifier,
        private ComponentGraphRelations $relations = new ComponentGraphRelations(),
    ) {
        foreach ($relations->dependencies() as $dependency) {
            if ($dependency->target()->value() === $identifier->value()) {
                throw new InvalidArgumentException('Component graph declaration cannot depend on itself.');
            }
        }

        foreach ($relations->conflicts() as $conflict) {
            if ($conflict->target()->value() === $identifier->value()) {
                throw new InvalidArgumentException('Component graph declaration cannot conflict with itself.');
            }
        }
    }

    public function identifier(): ComponentIdentifier
    {
        return $this->identifier;
    }

    public function relations(): ComponentGraphRelations
    {
        return $this->relations;
    }
}
