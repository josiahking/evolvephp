<?php

declare(strict_types=1);

namespace Evolve\DevTools\Adoption;

use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class DataOwnership
{
    public function __construct(
        private string $dataSet,
        private string $currentStore,
        private string $targetStore,
        private OwnershipSystem $currentWriter,
        private OwnershipSystem $targetWriter,
        private DataMigrationState $currentState,
        private DataMigrationState $targetState,
        private ?string $temporarySynchronization,
    ) {
        if (trim($dataSet) === '') {
            throw new InvalidArgumentException('Data set identifier must be non-empty.');
        }

        if (trim($currentStore) === '') {
            throw new InvalidArgumentException('Current store must be non-empty.');
        }

        if (trim($targetStore) === '') {
            throw new InvalidArgumentException('Target store must be non-empty.');
        }

        if ($temporarySynchronization !== null && trim($temporarySynchronization) === '') {
            throw new InvalidArgumentException('Temporary synchronization must be non-empty when supplied.');
        }
    }

    public function dataSet(): string
    {
        return $this->dataSet;
    }

    public function currentStore(): string
    {
        return $this->currentStore;
    }

    public function targetStore(): string
    {
        return $this->targetStore;
    }

    public function currentWriter(): OwnershipSystem
    {
        return $this->currentWriter;
    }

    public function targetWriter(): OwnershipSystem
    {
        return $this->targetWriter;
    }

    public function currentState(): DataMigrationState
    {
        return $this->currentState;
    }

    public function targetState(): DataMigrationState
    {
        return $this->targetState;
    }

    public function temporarySynchronization(): ?string
    {
        return $this->temporarySynchronization;
    }
}
