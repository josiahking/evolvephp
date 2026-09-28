<?php

declare(strict_types=1);

namespace Evolve\DevTools\Database;

use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class DatabaseFamilyAssessment
{
    /**
     * @var list<DatabaseCapability>
     */
    private array $matchedRequiredCapabilities;

    /**
     * @var list<DatabaseCapability>
     */
    private array $missingRequiredCapabilities;

    /**
     * @var list<DatabaseCapability>
     */
    private array $matchedPreferredCapabilities;

    /**
     * @var list<DatabaseCapability>
     */
    private array $missingPreferredCapabilities;

    /**
     * @var list<DatabaseCapability>
     */
    private array $conflictingExcludedCapabilities;

    /**
     * @param array<mixed> $matchedRequiredCapabilities
     * @param array<mixed> $missingRequiredCapabilities
     * @param array<mixed> $matchedPreferredCapabilities
     * @param array<mixed> $missingPreferredCapabilities
     * @param array<mixed> $conflictingExcludedCapabilities
     */
    public function __construct(
        private DatabaseFamilyDefinition $family,
        array $matchedRequiredCapabilities,
        array $missingRequiredCapabilities,
        array $matchedPreferredCapabilities,
        array $missingPreferredCapabilities,
        array $conflictingExcludedCapabilities,
    ) {
        $this->matchedRequiredCapabilities = self::capabilityList($matchedRequiredCapabilities);
        $this->missingRequiredCapabilities = self::capabilityList($missingRequiredCapabilities);
        $this->matchedPreferredCapabilities = self::capabilityList($matchedPreferredCapabilities);
        $this->missingPreferredCapabilities = self::capabilityList($missingPreferredCapabilities);
        $this->conflictingExcludedCapabilities = self::capabilityList($conflictingExcludedCapabilities);
    }

    public function family(): DatabaseFamilyDefinition
    {
        return $this->family;
    }

    public function suitable(): bool
    {
        return $this->missingRequiredCapabilities === []
            && $this->conflictingExcludedCapabilities === [];
    }

    public function preferenceMatchCount(): int
    {
        return count($this->matchedPreferredCapabilities);
    }

    /**
     * @return list<DatabaseCapability>
     */
    public function matchedRequiredCapabilities(): array
    {
        return $this->matchedRequiredCapabilities;
    }

    /**
     * @return list<DatabaseCapability>
     */
    public function missingRequiredCapabilities(): array
    {
        return $this->missingRequiredCapabilities;
    }

    /**
     * @return list<DatabaseCapability>
     */
    public function matchedPreferredCapabilities(): array
    {
        return $this->matchedPreferredCapabilities;
    }

    /**
     * @return list<DatabaseCapability>
     */
    public function missingPreferredCapabilities(): array
    {
        return $this->missingPreferredCapabilities;
    }

    /**
     * @return list<DatabaseCapability>
     */
    public function conflictingExcludedCapabilities(): array
    {
        return $this->conflictingExcludedCapabilities;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<DatabaseCapability>
     */
    private static function capabilityList(array $values): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('Database assessment capabilities must be a list.');
        }

        foreach ($values as $value) {
            if (! $value instanceof DatabaseCapability) {
                throw new InvalidArgumentException('Database assessment capabilities may contain only database capabilities.');
            }
        }

        usort(
            $values,
            static fn(DatabaseCapability $first, DatabaseCapability $second): int => $first->value <=> $second->value,
        );

        return $values;
    }
}
