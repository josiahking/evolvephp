<?php

declare(strict_types=1);

namespace Evolve\DevTools\Database;

use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class DatabaseAdvice
{
    /**
     * @var list<DatabaseFamilyAssessment>
     */
    private array $recommendations;

    /**
     * @var list<DatabaseFamilyAssessment>
     */
    private array $alternatives;

    /**
     * @var list<DatabaseFamilyAssessment>
     */
    private array $disqualified;

    /**
     * @param array<mixed> $recommendations
     * @param array<mixed> $alternatives
     * @param array<mixed> $disqualified
     */
    public function __construct(
        private DatabaseWorkloadProfile $profile,
        array $recommendations,
        array $alternatives,
        array $disqualified,
    ) {
        $this->recommendations = self::assessmentList($recommendations);
        $this->alternatives = self::assessmentList($alternatives);
        $this->disqualified = self::assessmentList($disqualified);
    }

    public function profile(): DatabaseWorkloadProfile
    {
        return $this->profile;
    }

    /**
     * @return list<DatabaseFamilyAssessment>
     */
    public function recommendations(): array
    {
        return $this->recommendations;
    }

    /**
     * @return list<DatabaseFamilyAssessment>
     */
    public function alternatives(): array
    {
        return $this->alternatives;
    }

    /**
     * @return list<DatabaseFamilyAssessment>
     */
    public function disqualified(): array
    {
        return $this->disqualified;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<DatabaseFamilyAssessment>
     */
    private static function assessmentList(array $values): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('Database advice assessments must be a list.');
        }

        foreach ($values as $value) {
            if (! $value instanceof DatabaseFamilyAssessment) {
                throw new InvalidArgumentException('Database advice assessments may contain only family assessments.');
            }
        }

        return $values;
    }
}
