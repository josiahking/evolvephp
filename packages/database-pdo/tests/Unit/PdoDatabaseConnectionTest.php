<?php

declare(strict_types=1);

namespace Evolve\Database\Pdo\Tests\Unit;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseFailureCategory;
use Evolve\Database\Contracts\DatabaseOperation;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Database\Pdo\Exception\PdoDatabaseException;
use Evolve\Database\Pdo\PdoDatabaseConnection;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

final class PdoDatabaseConnectionTest extends TestCase
{
    public function testConnectionImplementsContractsAndDoesNotMutateCallerOwnedPdoAttributes(): void
    {
        $pdo = $this->sqlite();
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_NUM);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->assertContains(DatabaseConnection::class, class_implements(PdoDatabaseConnection::class));
        $this->assertContains(ResetParticipant::class, class_implements(PdoDatabaseConnection::class));
        $connection = new PdoDatabaseConnection($pdo);
        $connection->reset();

        $this->assertSame(PDO::FETCH_NUM, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function testExecuteBindsParametersExplicitlyAndReturnsAffectedRows(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, active INTEGER NOT NULL, score TEXT NOT NULL, name TEXT NULL)');

        $connection = new PdoDatabaseConnection($pdo);

        $affectedRows = $connection->execute(new DatabaseStatement(
            'INSERT INTO users (active, score, name) VALUES (:active, :score, :name)',
            ['active' => true, 'score' => 1.25, 'name' => null],
            'create-user',
        ));

        $this->assertSame(1, $affectedRows);
        $this->assertSame(
            [['active' => 1, 'score' => '1.25', 'name' => null]],
            $pdo->query('SELECT active, score, name FROM users')->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function testQueryBindsZeroIndexedParametersAsOneBasedAndClosesCursor(): void
    {
        $statement = new RecordingPdoStatement([['answer' => 42]]);
        $pdo = new RecordingPdo($statement);
        $connection = new PdoDatabaseConnection($pdo);

        $rows = $connection->query(new DatabaseStatement('SELECT ? AS answer', [42], 'answer-query'));

        $this->assertSame([['answer' => 42]], $rows);
        $this->assertSame('SELECT ? AS answer', $pdo->preparedSql);
        $this->assertSame([[1, 42, PDO::PARAM_INT]], $statement->boundValues);
        $this->assertSame([PDO::FETCH_ASSOC], $statement->fetchAllModes);
        $this->assertSame(1, $statement->closeCursorCalls);
        $this->assertSame([], $statement->executeArguments);
    }

    public function testNamedParameterWithoutColonIsBoundWithColon(): void
    {
        $statement = new RecordingPdoStatement();
        $connection = new PdoDatabaseConnection(new RecordingPdo($statement));

        $connection->execute(new DatabaseStatement('UPDATE users SET name = :name WHERE id = :user_id', ['user_id' => 9, 'name' => 'Ada']));

        $this->assertSame(
            [
                [':user_id', 9, PDO::PARAM_INT],
                [':name', 'Ada', PDO::PARAM_STR],
            ],
            $statement->boundValues,
        );
    }

    public function testFloatParametersPreserveDoublePrecisionAsStringBindings(): void
    {
        $statement = new RecordingPdoStatement();
        $connection = new PdoDatabaseConnection(new RecordingPdo($statement));

        $connection->execute(new DatabaseStatement(
            'UPDATE readings SET ordinary = :ordinary, precise = :precise',
            ['ordinary' => 1.25, 'precise' => 0.12345678901234568],
            'update-reading',
        ));

        $this->assertSame(
            [
                [':ordinary', '1.25', PDO::PARAM_STR],
                [':precise', '0.12345678901234568', PDO::PARAM_STR],
            ],
            $statement->boundValues,
        );
        $formerRepresentation = rtrim(rtrim(sprintf('%.14F', 0.12345678901234568), '0'), '.');

        $this->assertNotContains($formerRepresentation, array_column($statement->boundValues, 1));
    }

    public function testPrepareFalseIsTranslatedWithoutSqlOrParameterMetadata(): void
    {
        $pdo = new RecordingPdo(false, ['42000', 1064, 'syntax near secret_table']);
        $connection = new PdoDatabaseConnection($pdo);

        try {
            $connection->execute(new DatabaseStatement('DELETE FROM secret_table WHERE token = ?', ['hidden'], 'delete-secret'));
            $this->fail('Expected PDO database exception.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::Execute, $exception->operation());
            $this->assertSame(DatabaseFailureCategory::Statement, $exception->category());
            $this->assertSame('delete-secret', $exception->operationName());
            $this->assertSame('42000', $exception->sqlState());
            $this->assertSame('1064', $exception->vendorCode());
            $this->assertStringNotContainsString('secret_table', $exception->getMessage());
            $this->assertStringNotContainsString('hidden', $exception->getMessage());
        }
    }

    public function testPrepareThrowIsTranslatedWithOperationNameAndCause(): void
    {
        $cause = new RuntimeException('prepare failed');
        $pdo = new RecordingPdo(new RecordingPdoStatement());
        $pdo->prepareFailure = $cause;
        $connection = new PdoDatabaseConnection($pdo);

        try {
            $connection->execute(new DatabaseStatement('SELECT 1', [], 'prepare-op'));
            $this->fail('Expected prepare failure.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::Execute, $exception->operation());
            $this->assertSame('prepare-op', $exception->operationName());
            $this->assertSame($cause, $exception->getPrevious());
        }
    }

    public function testBindFalseIsTranslatedWithOperationName(): void
    {
        $statement = new RecordingPdoStatement();
        $statement->bindResult = false;
        $connection = new PdoDatabaseConnection(new RecordingPdo($statement));

        try {
            $connection->execute(new DatabaseStatement('SELECT ?', ['value'], 'bind-op'));
            $this->fail('Expected bind failure.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::Execute, $exception->operation());
            $this->assertSame('bind-op', $exception->operationName());
        }
    }

    public function testBindThrowIsTranslatedWithOperationNameAndCause(): void
    {
        $cause = new RuntimeException('bind failed');
        $statement = new RecordingPdoStatement();
        $statement->bindFailure = $cause;
        $connection = new PdoDatabaseConnection(new RecordingPdo($statement));

        try {
            $connection->query(new DatabaseStatement('SELECT :value', ['value' => 'x'], 'bind-query'));
            $this->fail('Expected bind exception.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::Query, $exception->operation());
            $this->assertSame('bind-query', $exception->operationName());
            $this->assertSame($cause, $exception->getPrevious());
        }
    }

    public function testExecuteFalseIsTranslatedFromStatementErrorInfo(): void
    {
        $statement = new RecordingPdoStatement();
        $statement->executeResult = false;
        $statement->errorInfo = ['23505', 19, 'unique failed'];

        $connection = new PdoDatabaseConnection(new RecordingPdo($statement));

        $this->expectException(PdoDatabaseException::class);

        try {
            $connection->execute(new DatabaseStatement('INSERT INTO users (email) VALUES (?)', ['a@example.test']));
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseFailureCategory::Constraint, $exception->category());
            $this->assertSame(DatabaseOperation::Execute, $exception->operation());
            throw $exception;
        }
    }

    public function testExecuteThrowIsTranslatedWithCausalThrowable(): void
    {
        $cause = new RuntimeException('execute failed');
        $statement = new RecordingPdoStatement();
        $statement->executeFailure = $cause;
        $connection = new PdoDatabaseConnection(new RecordingPdo($statement));

        try {
            $connection->execute(new DatabaseStatement('UPDATE users SET name = ?', ['Ada'], 'execute-op'));
            $this->fail('Expected execute exception.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::Execute, $exception->operation());
            $this->assertSame('execute-op', $exception->operationName());
            $this->assertSame($cause, $exception->getPrevious());
        }
    }

    public function testTransactionCommitsAndReturnsCallbackResult(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE events (name TEXT NOT NULL)');
        $connection = new PdoDatabaseConnection($pdo);

        $expected = new stdClass();

        $result = $connection->transaction(function (DatabaseConnection $transaction) use ($expected): object {
            $transaction->execute(new DatabaseStatement('INSERT INTO events (name) VALUES (?)', ['created']));

            return $expected;
        });

        $this->assertSame($expected, $result);
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame([['name' => 'created']], $pdo->query('SELECT name FROM events')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testTransactionRollsBackAndRethrowsCallbackFailureUnchanged(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE events (name TEXT NOT NULL)');
        $connection = new PdoDatabaseConnection($pdo);
        $failure = new RuntimeException('callback failed');

        try {
            $connection->transaction(function (DatabaseConnection $transaction) use ($failure): void {
                $transaction->execute(new DatabaseStatement('INSERT INTO events (name) VALUES (?)', ['created']));

                $this->throwThrowable($failure);
            });
        } catch (Throwable $throwable) {
            $this->assertSame($failure, $throwable);
        }

        $this->assertSame([], $pdo->query('SELECT name FROM events')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testNestedAndForeignTransactionsAreRejected(): void
    {
        $pdo = $this->sqlite();
        $connection = new PdoDatabaseConnection($pdo);

        try {
            $connection->transaction(fn(DatabaseConnection $transaction): mixed => $transaction->transaction(fn(): string => 'nested'));
            $this->fail('Expected nested transaction rejection.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::TransactionBegin, $exception->operation());
            $this->assertSame(DatabaseFailureCategory::Transaction, $exception->category());
        }

        $pdo->beginTransaction();
        try {
            $connection->transaction(fn(): string => 'foreign');
            $this->fail('Expected foreign transaction rejection.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::TransactionBegin, $exception->operation());
        } finally {
            $pdo->rollBack();
        }
    }

    public function testBeginTransactionFalseAndThrowAreTranslated(): void
    {
        $falsePdo = new TransactionPdo();
        $falsePdo->beginResult = false;

        try {
            (new PdoDatabaseConnection($falsePdo))->transaction(fn(): string => 'never');
            $this->fail('Expected begin false failure.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::TransactionBegin, $exception->operation());
        }

        $cause = new RuntimeException('begin failed');
        $throwingPdo = new TransactionPdo();
        $throwingPdo->beginFailure = $cause;

        try {
            (new PdoDatabaseConnection($throwingPdo))->transaction(fn(): string => 'never');
            $this->fail('Expected begin throw failure.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::TransactionBegin, $exception->operation());
            $this->assertSame($cause, $exception->getPrevious());
        }
    }

    public function testCommitFalseAndThrowAreTranslatedAndActiveOwnershipCanReset(): void
    {
        $falsePdo = new TransactionPdo();
        $falsePdo->commitResult = false;
        $falseConnection = new PdoDatabaseConnection($falsePdo);

        try {
            $falseConnection->transaction(fn(): string => 'commit');
            $this->fail('Expected commit false failure.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::TransactionCommit, $exception->operation());
        }

        $falseConnection->reset();
        $this->assertSame(1, $falsePdo->rollbackCalls);

        $cause = new RuntimeException('commit failed');
        $throwingPdo = new TransactionPdo();
        $throwingPdo->commitFailure = $cause;
        $throwingConnection = new PdoDatabaseConnection($throwingPdo);

        try {
            $throwingConnection->transaction(fn(): string => 'commit');
            $this->fail('Expected commit throw failure.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::TransactionCommit, $exception->operation());
            $this->assertSame($cause, $exception->getPrevious());
        }

        $throwingConnection->reset();
        $this->assertSame(1, $throwingPdo->rollbackCalls);
    }

    public function testRollbackFailurePreservesCallbackThrowableAsPrimaryFailure(): void
    {
        $pdo = new TransactionPdo();
        $pdo->rollbackResult = false;
        $pdo->errorInfo = ['40001', null, 'rollback failed'];
        $connection = new PdoDatabaseConnection($pdo);
        $primary = new RuntimeException('callback failed');

        try {
            $connection->transaction(fn(): mixed => $this->throwThrowable($primary));
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::TransactionRollback, $exception->operation());
            $this->assertSame($primary, $exception->primaryFailure());
        }
    }

    public function testRollbackThrowPreservesRollbackCauseAndCallbackPrimaryFailure(): void
    {
        $rollbackFailure = new RuntimeException('rollback failed');
        $primary = new RuntimeException('callback failed');
        $pdo = new TransactionPdo();
        $pdo->rollbackFailure = $rollbackFailure;
        $connection = new PdoDatabaseConnection($pdo);

        $exception = $this->capturePdoException(function () use ($connection, $primary): void {
            $connection->transaction(function () use ($primary): void {
                $this->throwThrowable($primary);
            });
        });

        $this->assertSame(DatabaseOperation::TransactionRollback, $exception->operation());
        $this->assertSame($rollbackFailure, $exception->getPrevious());
        $this->assertSame($primary, $exception->primaryFailure());
    }

    public function testLostTransactionStateBeforeCommitFailsDeterministicallyAndCleansOwnership(): void
    {
        $pdo = new TransactionPdo();
        $connection = new PdoDatabaseConnection($pdo);

        try {
            $connection->transaction(function () use ($pdo): string {
                $pdo->loseTransaction();

                return 'implicit-commit';
            });
            $this->fail('Expected lost transaction commit failure.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::TransactionCommit, $exception->operation());
            $this->assertSame(DatabaseFailureCategory::Transaction, $exception->category());
        }

        $connection->reset();
    }

    public function testLostTransactionStateBeforeRollbackFailsDeterministicallyAndCleansOwnership(): void
    {
        $pdo = new TransactionPdo();
        $connection = new PdoDatabaseConnection($pdo);
        $primary = new RuntimeException('callback failed');

        $exception = $this->capturePdoException(function () use ($connection, $pdo, $primary): void {
            $connection->transaction(function () use ($pdo, $primary): void {
                $pdo->loseTransaction();
                $this->throwThrowable($primary);
            });
        });

        $this->assertSame(DatabaseOperation::TransactionRollback, $exception->operation());
        $this->assertSame(DatabaseFailureCategory::Transaction, $exception->category());
        $this->assertSame($primary, $exception->primaryFailure());
        $connection->reset();
    }

    public function testResetRollsBackOnlyOwnedTransactionsAndFailsClosedOnForeignTransaction(): void
    {
        $owned = new TransactionPdo();
        $connection = new PdoDatabaseConnection($owned);

        try {
            $connection->transaction(function () use ($connection): void {
                $connection->reset();
                $this->throwThrowable(new RuntimeException('reset completed unexpectedly'));
            });
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::TransactionRollback, $exception->operation());
        }

        $this->assertSame(1, $owned->rollbackCalls);

        $foreign = new TransactionPdo();
        $foreign->beginTransaction();
        $foreignConnection = new PdoDatabaseConnection($foreign);

        try {
            $foreignConnection->reset();
            $this->fail('Expected foreign transaction reset failure.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseFailureCategory::Transaction, $exception->category());
            $this->assertSame(0, $foreign->rollbackCalls);
        }
    }

    public function testResetSurfacesRollbackFailure(): void
    {
        $pdo = new TransactionPdo();
        $connection = new PdoDatabaseConnection($pdo);

        try {
            $connection->transaction(function () use ($connection, $pdo): void {
                $pdo->rollbackFailure = new RuntimeException('reset rollback failed');
                $connection->reset();
            });
            $this->fail('Expected reset rollback failure.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::TransactionRollback, $exception->operation());
            $this->assertSame('reset rollback failed', $exception->getPrevious()?->getMessage());
        }

        $this->assertSame(2, $pdo->rollbackCalls);
    }

    public function testResetSurfacesStaleOwnedTransactionAndClearsOwnership(): void
    {
        $pdo = new TransactionPdo();
        $connection = new PdoDatabaseConnection($pdo);

        try {
            $connection->transaction(function () use ($connection, $pdo): void {
                $pdo->loseTransaction();
                $connection->reset();
            });
            $this->fail('Expected stale reset failure.');
        } catch (PdoDatabaseException $exception) {
            $this->assertSame(DatabaseOperation::TransactionRollback, $exception->operation());
            $this->assertSame(DatabaseFailureCategory::Transaction, $exception->category());
        }

        $connection->reset();
    }

    private function sqlite(): PDO
    {
        return new PDO('sqlite::memory:');
    }

    private function throwThrowable(Throwable $throwable): never
    {
        throw $throwable;
    }

    private function capturePdoException(callable $operation): PdoDatabaseException
    {
        try {
            $operation();
        } catch (PdoDatabaseException $exception) {
            return $exception;
        }

        $this->fail('Expected PDO database exception.');
    }
}

final class RecordingPdo extends PDO
{
    public string $preparedSql = '';
    public ?Throwable $prepareFailure = null;

    /**
     * @param PDOStatement|false $prepareResult
     * @param array<int, mixed> $errorInfo
     */
    public function __construct(private PDOStatement|false $prepareResult, private array $errorInfo = ['00000', null, null]) {}

    /**
     * @param array<int|string, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->prepareFailure !== null) {
            throw $this->prepareFailure;
        }

        $this->preparedSql = $query;

        return $this->prepareResult;
    }

    /**
     * @return array<int, mixed>
     */
    public function errorInfo(): array
    {
        return $this->errorInfo;
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return 'sqlite';
        }

        return null;
    }
}

final class RecordingPdoStatement extends PDOStatement
{
    /**
     * @var list<array{0:int|string, 1:mixed, 2:int}>
     */
    public array $boundValues = [];

    /**
     * @var list<array<int, mixed>>
     */
    public array $executeArguments = [];

    /**
     * @var list<int>
     */
    public array $fetchAllModes = [];

    /**
     * @var array<int, mixed>
     */
    public array $errorInfo = ['00000', null, null];

    public bool $bindResult = true;
    public ?Throwable $bindFailure = null;
    public bool $executeResult = true;
    public ?Throwable $executeFailure = null;
    public int $closeCursorCalls = 0;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(private array $rows = [], private int $rowCount = 1) {}

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        if ($this->bindFailure !== null) {
            throw $this->bindFailure;
        }

        $this->boundValues[] = [$param, $value, $type];

        return $this->bindResult;
    }

    /**
     * @param array<int|string, mixed>|null $params
     */
    public function execute(?array $params = null): bool
    {
        if ($this->executeFailure !== null) {
            throw $this->executeFailure;
        }

        if ($params !== null) {
            $this->executeArguments[] = $params;
        }

        return $this->executeResult;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $this->fetchAllModes[] = $mode;

        return $this->rows;
    }

    public function closeCursor(): bool
    {
        ++$this->closeCursorCalls;

        return true;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    /**
     * @return array<int, mixed>
     */
    public function errorInfo(): array
    {
        return $this->errorInfo;
    }
}

final class TransactionPdo extends PDO
{
    /**
     * @var array<int, mixed>
     */
    public array $errorInfo = ['00000', null, null];

    public bool $beginResult = true;
    public ?Throwable $beginFailure = null;
    public bool $commitResult = true;
    public ?Throwable $commitFailure = null;
    public bool $rollbackResult = true;
    public ?Throwable $rollbackFailure = null;
    public int $rollbackCalls = 0;
    private bool $inTransaction = false;

    public function __construct() {}

    public function beginTransaction(): bool
    {
        if ($this->beginFailure !== null) {
            throw $this->beginFailure;
        }

        if ($this->beginResult) {
            $this->inTransaction = true;
        }

        return $this->beginResult;
    }

    public function commit(): bool
    {
        if ($this->commitFailure !== null) {
            throw $this->commitFailure;
        }

        if ($this->commitResult) {
            $this->inTransaction = false;
        }

        return $this->commitResult;
    }

    public function rollBack(): bool
    {
        ++$this->rollbackCalls;

        if ($this->rollbackFailure !== null) {
            throw $this->rollbackFailure;
        }

        if ($this->rollbackResult) {
            $this->inTransaction = false;
        }

        return $this->rollbackResult;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function loseTransaction(): void
    {
        $this->inTransaction = false;
    }

    /**
     * @return array<int, mixed>
     */
    public function errorInfo(): array
    {
        return $this->errorInfo;
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return 'sqlite';
        }

        return null;
    }
}
