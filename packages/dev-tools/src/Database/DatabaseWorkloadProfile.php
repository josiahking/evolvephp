<?php

declare(strict_types=1);

namespace Evolve\DevTools\Database;

use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class DatabaseWorkloadProfile
{
    /**
     * @var list<DatabaseCapability>
     */
    private array $requiredCapabilities;

    /**
     * @var list<DatabaseCapability>
     */
    private array $preferredCapabilities;

    /**
     * @var list<DatabaseCapability>
     */
    private array $excludedCapabilities;

    /**
     * @param array<mixed> $requiredCapabilities
     * @param array<mixed> $preferredCapabilities
     * @param array<mixed> $excludedCapabilities
     */
    public function __construct(
        private string $name,
        array $requiredCapabilities,
        array $preferredCapabilities,
        array $excludedCapabilities,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Database workload profile name must be non-empty.');
        }

        $this->requiredCapabilities = self::capabilityList($requiredCapabilities, 'Required database capabilities');
        $this->preferredCapabilities = self::capabilityList($preferredCapabilities, 'Preferred database capabilities');
        $this->excludedCapabilities = self::capabilityList($excludedCapabilities, 'Excluded database capabilities');

        if ($this->requiredCapabilities === [] && $this->preferredCapabilities === [] && $this->excludedCapabilities === []) {
            throw new InvalidArgumentException('Database workload profile must contain at least one capability.');
        }

        $declared = [];

        foreach ([$this->requiredCapabilities, $this->preferredCapabilities, $this->excludedCapabilities] as $capabilities) {
            foreach ($capabilities as $capability) {
                if (isset($declared[$capability->value])) {
                    throw new InvalidArgumentException('Database workload profile capability collections must be pairwise disjoint.');
                }

                $declared[$capability->value] = true;
            }
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<DatabaseCapability>
     */
    public function requiredCapabilities(): array
    {
        return $this->requiredCapabilities;
    }

    /**
     * @return list<DatabaseCapability>
     */
    public function preferredCapabilities(): array
    {
        return $this->preferredCapabilities;
    }

    /**
     * @return list<DatabaseCapability>
     */
    public function excludedCapabilities(): array
    {
        return $this->excludedCapabilities;
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
}
