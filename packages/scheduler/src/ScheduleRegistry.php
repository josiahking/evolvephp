<?php

declare(strict_types=1);

namespace Evolve\Scheduler;

use InvalidArgumentException;

/** @experimental This API may change before stable release. */
final readonly class ScheduleRegistry
{
    /** @var list<ScheduleDefinition> */
    private array $definitions;

    /** @param iterable<mixed> $definitions */
    public function __construct(iterable $definitions)
    {
        $collected = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof ScheduleDefinition) {
                throw new InvalidArgumentException('Schedule registry requires schedule definitions.');
            }
            if (isset($collected[$definition->identifier()])) {
                throw new InvalidArgumentException('Duplicate schedule identifier.');
            }
            $collected[$definition->identifier()] = $definition;
        }
        ksort($collected, SORT_STRING);
        $this->definitions = array_values($collected);
    }

    /** @param iterable<mixed> $contributors */
    public static function fromContributors(iterable $contributors): self
    {
        $seen = [];
        $definitions = [];
        foreach ($contributors as $contributor) {
            if (!$contributor instanceof ScheduleContributor) {
                throw new InvalidArgumentException('Contributors must implement ScheduleContributor.');
            }
            $identifier = $contributor->identifier();
            ScheduleDefinition::assertIdentifier($identifier);
            if (isset($seen[$identifier])) {
                throw new InvalidArgumentException('Duplicate contributor identifier.');
            }
            $seen[$identifier] = true;
            foreach ($contributor->definitions() as $definition) {
                $definitions[] = $definition;
            }
        }

        return new self($definitions);
    }

    /** @return list<ScheduleDefinition> */
    public function definitions(): array
    {
        return $this->definitions;
    }
}
