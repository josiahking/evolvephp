<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Component\ComponentIdentifier;
use InvalidArgumentException;

/**
 * Raised when active component dependency and capability edges contain a cycle.
 *
 * @experimental
 */
final class ComponentDependencyCycle extends ComponentGraphResolutionFailed
{
    /**
     * @var list<ComponentIdentifier>
     */
    private array $cycle;

    /**
     * @param list<ComponentIdentifier> $cycle
     */
    public function __construct(array $cycle)
    {
        self::assertCycle($cycle);

        $this->cycle = $cycle;

        parent::__construct('Component dependency cycle detected.');
    }

    /**
     * @param array<mixed> $cycle
     */
    private static function assertCycle(array $cycle): void
    {
        if (!array_is_list($cycle)) {
            throw new InvalidArgumentException('Dependency cycle must be a list.');
        }

        if (count($cycle) < 3) {
            throw new InvalidArgumentException('Dependency cycle must contain a closed chain.');
        }

        $values = [];

        foreach ($cycle as $identifier) {
            if (!$identifier instanceof ComponentIdentifier) {
                throw new InvalidArgumentException('Dependency cycle must contain component identifiers.');
            }

            $values[] = $identifier->value();
        }

        if ($values[0] !== $values[count($values) - 1]) {
            throw new InvalidArgumentException('Dependency cycle must repeat the starting identifier at the end.');
        }

        $uniqueValues = array_values(array_unique(array_slice($values, 0, -1)));

        if (count($uniqueValues) < 2) {
            throw new InvalidArgumentException('Dependency cycle must contain at least two participating identifiers.');
        }

        $sortedValues = $uniqueValues;
        usort($sortedValues, 'strcmp');

        if ($values[0] !== $sortedValues[0]) {
            throw new InvalidArgumentException('Dependency cycle must be canonically rotated.');
        }
    }

    /**
     * @return list<ComponentIdentifier>
     */
    public function cycle(): array
    {
        return $this->cycle;
    }
}
