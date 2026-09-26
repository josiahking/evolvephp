<?php

declare(strict_types=1);

namespace Evolve\Database\Pdo;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseOperation;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Database\Pdo\Exception\PdoDatabaseException;
use Evolve\Database\Pdo\Internal\PdoFailureTranslator;
use PDO;
use PDOStatement;
use Throwable;

/**
 * PDO implementation of the EvolvePHP database connection contract.
 *
 * @experimental EvolvePHP 2 is pre-beta; this public adapter API may change before stable release.
 */
final class PdoDatabaseConnection implements DatabaseConnection, ResetParticipant
{
    private readonly PdoFailureTranslator $translator;
    private bool $ownsTransaction = false;

    public function __construct(private readonly PDO $pdo)
    {
        $this->translator = new PdoFailureTranslator();
    }

    public function execute(DatabaseStatement $statement): int
    {
        $pdoStatement = $this->prepare($statement, DatabaseOperation::Execute);
        $this->bindValues($pdoStatement, $statement, DatabaseOperation::Execute);

        try {
            $executed = $pdoStatement->execute();
        } catch (Throwable $throwable) {
            throw $this->translator->translate(DatabaseOperation::Execute, $statement->operationName(), $this->pdo, $pdoStatement, $throwable);
        }

        if ($executed !== true) {
            throw $this->translator->translate(DatabaseOperation::Execute, $statement->operationName(), $this->pdo, $pdoStatement);
        }

        return $pdoStatement->rowCount();
    }

    public function query(DatabaseStatement $statement): iterable
    {
        $pdoStatement = $this->prepare($statement, DatabaseOperation::Query);
        $this->bindValues($pdoStatement, $statement, DatabaseOperation::Query);
        $primaryFailure = null;

        try {
            if ($pdoStatement->execute() !== true) {
                throw $this->translator->translate(DatabaseOperation::Query, $statement->operationName(), $this->pdo, $pdoStatement);
            }

            return $pdoStatement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PdoDatabaseException $exception) {
            $primaryFailure = $exception;
            throw $exception;
        } catch (Throwable $throwable) {
            $primaryFailure = $throwable;
            throw $this->translator->translate(DatabaseOperation::Query, $statement->operationName(), $this->pdo, $pdoStatement, $throwable);
        } finally {
            $this->closeCursor($pdoStatement, $statement, $primaryFailure);
        }
    }

    public function transaction(callable $operation): mixed
    {
        $this->ensureCanBeginTransaction(null);
        $this->beginTransaction(null);

        try {
            $result = $operation($this);
        } catch (Throwable $throwable) {
            $this->rollBackAfterCallbackFailure($throwable, null);
        }

        $this->commitTransaction(null);

        return $result;
    }

    public function reset(): void
    {
        if (!$this->ownsTransaction) {
            if ($this->pdo->inTransaction()) {
                throw $this->translator->transactionStateFailure(DatabaseOperation::TransactionRollback, null, $this->pdo);
            }

            return;
        }

        if (!$this->pdo->inTransaction()) {
            $this->ownsTransaction = false;

            throw $this->translator->transactionStateFailure(DatabaseOperation::TransactionRollback, null, $this->pdo);
        }

        try {
            $rolledBack = $this->pdo->rollBack();
        } catch (Throwable $throwable) {
            throw $this->translator->translate(DatabaseOperation::TransactionRollback, null, $this->pdo, null, $throwable);
        }

        if ($rolledBack !== true) {
            throw $this->translator->translate(DatabaseOperation::TransactionRollback, null, $this->pdo);
        }

        $this->ownsTransaction = false;
    }

    private function prepare(DatabaseStatement $statement, DatabaseOperation $operation): PDOStatement
    {
        try {
            $pdoStatement = $this->pdo->prepare($statement->sql());
        } catch (Throwable $throwable) {
            throw $this->translator->translate($operation, $statement->operationName(), $this->pdo, null, $throwable);
        }

        if (!$pdoStatement instanceof PDOStatement) {
            throw $this->translator->translate($operation, $statement->operationName(), $this->pdo);
        }

        return $pdoStatement;
    }

    private function bindValues(PDOStatement $pdoStatement, DatabaseStatement $statement, DatabaseOperation $operation): void
    {
        foreach ($statement->parameters() as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : ':' . ltrim($key, ':');

            try {
                $bound = $pdoStatement->bindValue($parameter, $this->normalizeValue($value), $this->parameterType($value));
            } catch (Throwable $throwable) {
                throw $this->translator->translate($operation, $statement->operationName(), $this->pdo, $pdoStatement, $throwable);
            }

            if ($bound !== true) {
                throw $this->translator->translate($operation, $statement->operationName(), $this->pdo, $pdoStatement);
            }
        }
    }

    private function normalizeValue(bool|int|float|string|null $value): bool|int|string|null
    {
        if (is_float($value)) {
            return sprintf('%.17H', $value);
        }

        return $value;
    }

    private function parameterType(bool|int|float|string|null $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
    }

    private function closeCursor(PDOStatement $pdoStatement, DatabaseStatement $statement, ?Throwable $primaryFailure): void
    {
        try {
            $closed = $pdoStatement->closeCursor();
        } catch (Throwable $throwable) {
            if ($primaryFailure !== null) {
                return;
            }

            throw $this->translator->translate(DatabaseOperation::Query, $statement->operationName(), $this->pdo, $pdoStatement, $throwable);
        }

        if ($closed !== true && $primaryFailure === null) {
            throw $this->translator->translate(DatabaseOperation::Query, $statement->operationName(), $this->pdo, $pdoStatement);
        }
    }

    private function ensureCanBeginTransaction(?string $operationName): void
    {
        if ($this->ownsTransaction || $this->pdo->inTransaction()) {
            throw $this->translator->transactionStateFailure(DatabaseOperation::TransactionBegin, $operationName, $this->pdo);
        }
    }

    private function beginTransaction(?string $operationName): void
    {
        try {
            $began = $this->pdo->beginTransaction();
        } catch (Throwable $throwable) {
            throw $this->translator->translate(DatabaseOperation::TransactionBegin, $operationName, $this->pdo, null, $throwable);
        }

        if ($began !== true) {
            throw $this->translator->translate(DatabaseOperation::TransactionBegin, $operationName, $this->pdo);
        }

        if (!$this->pdo->inTransaction()) {
            throw $this->translator->transactionStateFailure(DatabaseOperation::TransactionBegin, $operationName, $this->pdo);
        }

        $this->ownsTransaction = true;
    }

    private function commitTransaction(?string $operationName): void
    {
        if (!$this->ownsTransaction || !$this->pdo->inTransaction()) {
            $this->ownsTransaction = false;

            throw $this->translator->transactionStateFailure(DatabaseOperation::TransactionCommit, $operationName, $this->pdo);
        }

        try {
            $committed = $this->pdo->commit();
        } catch (Throwable $throwable) {
            $this->clearOwnershipWhenInactive();

            throw $this->translator->translate(DatabaseOperation::TransactionCommit, $operationName, $this->pdo, null, $throwable);
        }

        if ($committed !== true) {
            $this->clearOwnershipWhenInactive();

            throw $this->translator->translate(DatabaseOperation::TransactionCommit, $operationName, $this->pdo);
        }

        $this->ownsTransaction = false;
    }

    private function rollBackAfterCallbackFailure(Throwable $primaryFailure, ?string $operationName): never
    {
        if (!$this->ownsTransaction || !$this->pdo->inTransaction()) {
            $this->ownsTransaction = false;

            throw $this->translator->transactionStateFailure(DatabaseOperation::TransactionRollback, $operationName, $this->pdo, null, $primaryFailure);
        }

        try {
            $rolledBack = $this->pdo->rollBack();
        } catch (Throwable $throwable) {
            $this->clearOwnershipWhenInactive();

            throw $this->translator->translate(DatabaseOperation::TransactionRollback, $operationName, $this->pdo, null, $throwable, $primaryFailure);
        }

        if ($rolledBack !== true) {
            $this->clearOwnershipWhenInactive();

            throw $this->translator->translate(DatabaseOperation::TransactionRollback, $operationName, $this->pdo, null, null, $primaryFailure);
        }

        $this->ownsTransaction = false;

        throw $primaryFailure;
    }

    private function clearOwnershipWhenInactive(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->ownsTransaction = false;
        }
    }
}
