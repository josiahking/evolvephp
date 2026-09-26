<?php

declare(strict_types=1);

namespace Evolve\Database\Pdo\Internal;

use Evolve\Database\Contracts\DatabaseFailureCategory;
use Evolve\Database\Contracts\DatabaseOperation;
use Evolve\Database\Pdo\Exception\PdoDatabaseException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * @internal
 */
final class PdoFailureTranslator
{
    public function translate(
        DatabaseOperation $operation,
        ?string $operationName,
        PDO $pdo,
        ?PDOStatement $statement = null,
        ?Throwable $failure = null,
        ?Throwable $primaryFailure = null,
    ): PdoDatabaseException {
        $errorInfo = $this->errorInfo($pdo, $statement, $failure);
        $sqlState = $this->sqlState($errorInfo[0] ?? null);
        $vendorCode = $this->vendorCode($errorInfo[1] ?? null);

        return new PdoDatabaseException(
            $operation,
            $this->category($operation, $sqlState),
            $operationName,
            $this->driverName($pdo),
            $sqlState,
            $vendorCode,
            $failure,
            $primaryFailure,
        );
    }

    public function transactionStateFailure(
        DatabaseOperation $operation,
        ?string $operationName,
        PDO $pdo,
        ?Throwable $previous = null,
        ?Throwable $primaryFailure = null,
    ): PdoDatabaseException {
        return new PdoDatabaseException(
            $operation,
            DatabaseFailureCategory::Transaction,
            $operationName,
            $this->driverName($pdo),
            null,
            null,
            $previous,
            $primaryFailure,
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function errorInfo(PDO $pdo, ?PDOStatement $statement, ?Throwable $failure): array
    {
        if ($failure instanceof PDOException && is_array($failure->errorInfo)) {
            return $failure->errorInfo;
        }

        if ($statement !== null) {
            return $statement->errorInfo();
        }

        return $pdo->errorInfo();
    }

    private function category(DatabaseOperation $operation, ?string $sqlState): DatabaseFailureCategory
    {
        if ($sqlState === '40001') {
            return DatabaseFailureCategory::Serialization;
        }

        if ($sqlState === '40P01') {
            return DatabaseFailureCategory::Deadlock;
        }

        if ($sqlState === 'HYT00' || $sqlState === 'HYT01') {
            return DatabaseFailureCategory::Timeout;
        }

        if ($sqlState !== null) {
            return match (substr($sqlState, 0, 2)) {
                '08' => DatabaseFailureCategory::Connection,
                '23' => DatabaseFailureCategory::Constraint,
                '28' => DatabaseFailureCategory::Authentication,
                default => $this->fallbackCategory($operation),
            };
        }

        return $this->fallbackCategory($operation);
    }

    private function fallbackCategory(DatabaseOperation $operation): DatabaseFailureCategory
    {
        return match ($operation) {
            DatabaseOperation::Execute,
            DatabaseOperation::Query => DatabaseFailureCategory::Statement,
            DatabaseOperation::TransactionBegin,
            DatabaseOperation::TransactionCommit,
            DatabaseOperation::TransactionRollback => DatabaseFailureCategory::Transaction,
        };
    }

    private function driverName(PDO $pdo): ?string
    {
        try {
            $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable) {
            return null;
        }

        if (!is_string($driverName) || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $driverName) !== 1) {
            return null;
        }

        return $driverName;
    }

    private function sqlState(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^[A-Z0-9]{5}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function vendorCode(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (!is_string($value) || preg_match('/^[A-Za-z0-9_.:-]{1,32}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
