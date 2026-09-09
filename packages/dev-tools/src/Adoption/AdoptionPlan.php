<?php

declare(strict_types=1);

namespace Evolve\DevTools\Adoption;

use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class AdoptionPlan
{
    /**
     * @var list<string>
     */
    private array $migrationEvidence;

    /**
     * @var list<string>
     */
    private array $rollbackEvidence;

    /**
     * @var list<string>
     */
    private array $acceptanceCriteria;

    /**
     * @param array<mixed> $migrationEvidence
     * @param array<mixed> $rollbackEvidence
     * @param array<mixed> $acceptanceCriteria
     */
    public function __construct(
        private MigrationManifest $manifest,
        array $migrationEvidence,
        array $rollbackEvidence,
        array $acceptanceCriteria,
    ) {
        $this->migrationEvidence = self::requiredUniqueStringList($migrationEvidence, 'Migration evidence');
        $this->rollbackEvidence = self::requiredUniqueStringList($rollbackEvidence, 'Rollback evidence');
        $this->acceptanceCriteria = self::requiredUniqueStringList($acceptanceCriteria, 'Acceptance criteria');
    }

    public function manifest(): MigrationManifest
    {
        return $this->manifest;
    }

    /**
     * @return list<string>
     */
    public function migrationEvidence(): array
    {
        return $this->migrationEvidence;
    }

    /**
     * @return list<string>
     */
    public function rollbackEvidence(): array
    {
        return $this->rollbackEvidence;
    }

    /**
     * @return list<string>
     */
    public function acceptanceCriteria(): array
    {
        return $this->acceptanceCriteria;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function requiredUniqueStringList(array $values, string $label): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException($label . ' must be a list.');
        }

        if ($values === []) {
            throw new InvalidArgumentException($label . ' must contain at least one declaration.');
        }

        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException($label . ' may contain only non-empty strings.');
            }

            if (isset($seen[$value])) {
                throw new InvalidArgumentException($label . ' contains duplicate declarations.');
            }

            $seen[$value] = true;
        }

        return $values;
    }
}
