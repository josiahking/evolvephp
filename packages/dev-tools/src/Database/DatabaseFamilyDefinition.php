<?php

declare(strict_types=1);

namespace Evolve\DevTools\Database;

use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class DatabaseFamilyDefinition
{
    /**
     * @var list<DatabaseCapability>
     */
    private array $capabilities;

    /**
     * @var list<string>
     */
    private array $exampleEngines;

    /**
     * @var list<string>
     */
    private array $tradeOffs;

    /**
     * @param array<mixed> $capabilities
     * @param array<mixed> $exampleEngines
     * @param array<mixed> $tradeOffs
     */
    public function __construct(
        private string $identifier,
        private string $displayName,
        array $capabilities,
        array $exampleEngines,
        array $tradeOffs,
    ) {
        if (preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', $identifier) !== 1) {
            throw new InvalidArgumentException('Database family identifier is invalid.');
        }

        if (trim($displayName) === '') {
            throw new InvalidArgumentException('Database family display name must be non-empty.');
        }

        $this->capabilities = self::capabilityList(
            $capabilities,
            'Database family capabilities',
        );
        $this->exampleEngines = self::stringList(
            $exampleEngines,
            'Database family example engines',
            false,
            true,
        );
        $this->tradeOffs = self::stringList(
            $tradeOffs,
            'Database family trade-offs',
            true,
            false,
        );
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    /**
     * @return list<DatabaseCapability>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * @return list<string>
     */
    public function exampleEngines(): array
    {
        return $this->exampleEngines;
    }

    /**
     * @return list<string>
     */
    public function tradeOffs(): array
    {
        return $this->tradeOffs;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<DatabaseCapability>
     */
    private static function capabilityList(array $values, string $label): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException($label . ' must be a list.');
        }

        $seen = [];

        foreach ($values as $value) {
            if (! $value instanceof DatabaseCapability) {
                throw new InvalidArgumentException($label . ' may contain only database capabilities.');
            }

            if (isset($seen[$value->value])) {
                throw new InvalidArgumentException($label . ' contain duplicate capabilities.');
            }

            $seen[$value->value] = true;
        }

        usort(
            $values,
            static fn(DatabaseCapability $first, DatabaseCapability $second): int => $first->value <=> $second->value,
        );

        return $values;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function stringList(array $values, string $label, bool $required, bool $sort): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException($label . ' must be a list.');
        }

        if ($required && $values === []) {
            throw new InvalidArgumentException($label . ' must contain at least one value.');
        }

        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException($label . ' may contain only non-empty strings.');
            }

            if (isset($seen[$value])) {
                throw new InvalidArgumentException($label . ' contain duplicate values.');
            }

            $seen[$value] = true;
        }

        if ($sort) {
            sort($values, SORT_STRING);
        }

        return $values;
    }
}
