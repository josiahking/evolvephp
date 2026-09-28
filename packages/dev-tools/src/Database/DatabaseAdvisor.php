<?php

declare(strict_types=1);

namespace Evolve\DevTools\Database;

use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class DatabaseAdvisor
{
    public function __construct(
        private DatabaseCatalog $catalog,
    ) {}

    public function advise(DatabaseWorkloadProfile $profile): DatabaseAdvice
    {
        $viable = [];
        $disqualified = [];

        foreach ($this->catalog->families() as $family) {
            $assessment = $this->assess($family, $profile);

            if ($assessment->suitable()) {
                $viable[] = $assessment;

                continue;
            }

            $disqualified[] = $assessment;
        }

        usort($viable, self::compareViable(...));
        usort($disqualified, self::compareByIdentifier(...));

        if ($viable === []) {
            return new DatabaseAdvice($profile, [], [], $disqualified);
        }

        $highestPreferenceMatchCount = $viable[0]->preferenceMatchCount();
        $recommendations = [];
        $alternatives = [];

        foreach ($viable as $assessment) {
            if ($assessment->preferenceMatchCount() === $highestPreferenceMatchCount) {
                $recommendations[] = $assessment;

                continue;
            }

            $alternatives[] = $assessment;
        }

        return new DatabaseAdvice($profile, $recommendations, $alternatives, $disqualified);
    }

    /**
     * @param array<mixed> $profiles
     *
     * @return list<DatabaseAdvice>
     */
    public function adviseMany(array $profiles): array
    {
        if (! array_is_list($profiles)) {
            throw new InvalidArgumentException('Database workload profiles must be a list.');
        }

        if ($profiles === []) {
            throw new InvalidArgumentException('Database workload profiles must contain at least one profile.');
        }

        $names = [];
        $advice = [];

        foreach ($profiles as $profile) {
            if (! $profile instanceof DatabaseWorkloadProfile) {
                throw new InvalidArgumentException('Database workload profiles may contain only workload profiles.');
            }

            if (isset($names[$profile->name()])) {
                throw new InvalidArgumentException('Database workload profiles contain duplicate profile names.');
            }

            $names[$profile->name()] = true;
            $advice[] = $this->advise($profile);
        }

        return $advice;
    }

    private function assess(DatabaseFamilyDefinition $family, DatabaseWorkloadProfile $profile): DatabaseFamilyAssessment
    {
        $familyCapabilities = self::capabilityMap($family->capabilities());

        return new DatabaseFamilyAssessment(
            $family,
            self::presentCapabilities($profile->requiredCapabilities(), $familyCapabilities),
            self::missingCapabilities($profile->requiredCapabilities(), $familyCapabilities),
            self::presentCapabilities($profile->preferredCapabilities(), $familyCapabilities),
            self::missingCapabilities($profile->preferredCapabilities(), $familyCapabilities),
            self::presentCapabilities($profile->excludedCapabilities(), $familyCapabilities),
        );
    }

    /**
     * @param list<DatabaseCapability> $capabilities
     *
     * @return array<string, true>
     */
    private static function capabilityMap(array $capabilities): array
    {
        $map = [];

        foreach ($capabilities as $capability) {
            $map[$capability->value] = true;
        }

        return $map;
    }

    /**
     * @param list<DatabaseCapability> $capabilities
     * @param array<string, true> $available
     *
     * @return list<DatabaseCapability>
     */
    private static function presentCapabilities(array $capabilities, array $available): array
    {
        return array_values(array_filter(
            $capabilities,
            static fn(DatabaseCapability $capability): bool => isset($available[$capability->value]),
        ));
    }

    /**
     * @param list<DatabaseCapability> $capabilities
     * @param array<string, true> $available
     *
     * @return list<DatabaseCapability>
     */
    private static function missingCapabilities(array $capabilities, array $available): array
    {
        return array_values(array_filter(
            $capabilities,
            static fn(DatabaseCapability $capability): bool => ! isset($available[$capability->value]),
        ));
    }

    private static function compareViable(DatabaseFamilyAssessment $first, DatabaseFamilyAssessment $second): int
    {
        return ($second->preferenceMatchCount() <=> $first->preferenceMatchCount())
            ?: self::compareByIdentifier($first, $second);
    }

    private static function compareByIdentifier(DatabaseFamilyAssessment $first, DatabaseFamilyAssessment $second): int
    {
        return $first->family()->identifier() <=> $second->family()->identifier();
    }
}
