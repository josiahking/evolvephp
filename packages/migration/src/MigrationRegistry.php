<?php

declare(strict_types=1);

namespace Evolve\Migration;

use InvalidArgumentException;

/** @experimental This API may change before stable release. */
final readonly class MigrationRegistry
{
    /** @var list<MigrationDefinition> */
    private array $definitions;

    /** @param iterable<mixed> $definitions */
    public function __construct(iterable $definitions)
    {
        $collected = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof MigrationDefinition) {
                throw new InvalidArgumentException('Migration registry requires definitions.');
            }
            $key = $definition->fullIdentity();
            if (isset($collected[$key])) {
                throw new InvalidArgumentException('Duplicate full migration identity.');
            }
            $collected[$key] = $definition;
        }
        $ordered = array_values($collected);
        usort(
            $ordered,
            static fn(MigrationDefinition $a, MigrationDefinition $b): int
            => $a->order() <=> $b->order()
            ?: strcmp($a->owner()->key(), $b->owner()->key())
            ?: strcmp($a->identifier()->value(), $b->identifier()->value()),
        );
        $this->definitions = $ordered;
    }

    /** @param iterable<mixed> $contributors */
    public static function fromContributors(iterable $contributors): self
    {
        $seen = [];
        $definitions = [];
        foreach ($contributors as $contributor) {
            if (!$contributor instanceof MigrationContributor) {
                throw new InvalidArgumentException('Migration contributors must implement MigrationContributor.');
            }
            $identifier = $contributor->identifier();
            MigrationIdentifier::assertValid($identifier);
            if (isset($seen[$identifier])) {
                throw new InvalidArgumentException('Duplicate migration contributor identifier.');
            }
            $seen[$identifier] = true;
            $owner = $contributor->owner();
            foreach ($contributor->definitions() as $value) {
                $definition = self::requireDefinition($value);
                if ($definition->owner()->key() !== $owner->key()) {
                    throw new InvalidArgumentException('Migration contributor owner differs from definition owner.');
                }
                $definitions[] = $definition;
            }
        }
        return new self($definitions);
    }

    private static function requireDefinition(mixed $value): MigrationDefinition
    {
        if (!$value instanceof MigrationDefinition) {
            throw new InvalidArgumentException('Migration contributor must provide definitions.');
        }

        return $value;
    }

    /** @return list<MigrationDefinition> */
    public function definitions(): array
    {
        return $this->definitions;
    }
}
