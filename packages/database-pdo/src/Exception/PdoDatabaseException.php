<?php

declare(strict_types=1);

namespace Evolve\Database\Pdo\Exception;

use Evolve\Database\Contracts\DatabaseFailureCategory;
use Evolve\Database\Contracts\DatabaseOperation;
use Evolve\Database\Contracts\Exception\DatabaseException;
use RuntimeException;
use Throwable;

/**
 * Concrete PDO adapter failure with bounded portable metadata.
 *
 * @experimental EvolvePHP 2 is pre-beta; this public adapter API may change before stable release.
 */
final class PdoDatabaseException extends RuntimeException implements DatabaseException
{
    public function __construct(
        private readonly DatabaseOperation $operation,
        private readonly DatabaseFailureCategory $category,
        private readonly ?string $operationName = null,
        private readonly ?string $driverName = null,
        private readonly ?string $sqlState = null,
        private readonly ?string $vendorCode = null,
        ?Throwable $previous = null,
        private readonly ?Throwable $primaryFailure = null,
    ) {
        parent::__construct('PDO database operation failed.', 0, $previous);
    }

    public function operation(): DatabaseOperation
    {
        return $this->operation;
    }

    public function category(): DatabaseFailureCategory
    {
        return $this->category;
    }

    public function operationName(): ?string
    {
        return $this->operationName;
    }

    public function driverName(): ?string
    {
        return $this->driverName;
    }

    public function sqlState(): ?string
    {
        return $this->sqlState;
    }

    public function vendorCode(): ?string
    {
        return $this->vendorCode;
    }

    public function primaryFailure(): ?Throwable
    {
        return $this->primaryFailure;
    }
}
