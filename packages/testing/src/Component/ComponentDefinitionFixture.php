<?php

declare(strict_types=1);

namespace Evolve\Testing\Component;

use Closure;
use Evolve\Contracts\Component\ComponentDefinition;
use Evolve\Contracts\Component\ComponentEntryPoint;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentGraphRelations;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\ComponentType;

/**
 * Generic component definition fixture for framework and application tests.
 *
 * @experimental
 */
final readonly class ComponentDefinitionFixture implements ComponentDefinition
{
    private ComponentGraphDeclaration $graphDeclaration;

    /**
     * @param Closure(): ComponentEntryPoint $entryPointFactory
     * @param Closure(): void|null $validator
     */
    public function __construct(
        private ComponentIdentifier $identifier,
        private ComponentType $type,
        private Closure $entryPointFactory,
        ComponentGraphRelations $relations = new ComponentGraphRelations(),
        private ?Closure $validator = null,
    ) {
        $this->graphDeclaration = new ComponentGraphDeclaration($identifier, $relations);
    }

    public function identifier(): ComponentIdentifier
    {
        return $this->identifier;
    }

    public function type(): ComponentType
    {
        return $this->type;
    }

    public function graphDeclaration(): ComponentGraphDeclaration
    {
        return $this->graphDeclaration;
    }

    public function validate(): void
    {
        ($this->validator ?? static function (): void {})();
    }

    public function createEntryPoint(): ComponentEntryPoint
    {
        return ($this->entryPointFactory)();
    }
}
