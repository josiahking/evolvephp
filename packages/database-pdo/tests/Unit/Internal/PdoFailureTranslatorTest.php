<?php

declare(strict_types=1);

namespace Evolve\Database\Pdo\Tests\Unit\Internal;

use Evolve\Database\Contracts\DatabaseFailureCategory;
use Evolve\Database\Contracts\DatabaseOperation;
use Evolve\Database\Pdo\Internal\PdoFailureTranslator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdoFailureTranslatorTest extends TestCase
{
    public function testTranslatorUsesPdoExceptionErrorInfoBeforeHumanMessage(): void
    {
        $pdo = new TranslatorPdo(['00000', null, null], 'pgsql');
        $failure = new PDOException('duplicate email: secret@example.com');
        $failure->errorInfo = ['23000', 19, 'unique failed with hidden sql'];

        $exception = (new PdoFailureTranslator())->translate(
            DatabaseOperation::Execute,
            'create-user',
            $pdo,
            null,
            $failure,
        );

        $this->assertSame(DatabaseFailureCategory::Constraint, $exception->category());
        $this->assertSame('23000', $exception->sqlState());
        $this->assertSame('19', $exception->vendorCode());
        $this->assertSame('pgsql', $exception->driverName());
        $this->assertSame('create-user', $exception->operationName());
        $this->assertSame($failure, $exception->getPrevious());
        $this->assertStringNotContainsString('secret@example.com', $exception->getMessage());
        $this->assertStringNotContainsString('hidden sql', $exception->getMessage());
    }

    public function testTranslatorMapsPortableSqlstateCategories(): void
    {
        $translator = new PdoFailureTranslator();

        $this->assertSame(DatabaseFailureCategory::Serialization, $translator->translate(DatabaseOperation::Execute, null, new TranslatorPdo(['40001', null, null]))->category());
        $this->assertSame(DatabaseFailureCategory::Deadlock, $translator->translate(DatabaseOperation::Execute, null, new TranslatorPdo(['40P01', null, null]))->category());
        $this->assertSame(DatabaseFailureCategory::Timeout, $translator->translate(DatabaseOperation::Query, null, new TranslatorPdo(['HYT00', null, null]))->category());
        $this->assertSame(DatabaseFailureCategory::Timeout, $translator->translate(DatabaseOperation::Query, null, new TranslatorPdo(['HYT01', null, null]))->category());
        $this->assertSame(DatabaseFailureCategory::Connection, $translator->translate(DatabaseOperation::Query, null, new TranslatorPdo(['08006', null, null]))->category());
        $this->assertSame(DatabaseFailureCategory::Authentication, $translator->translate(DatabaseOperation::Query, null, new TranslatorPdo(['28000', null, null]))->category());
        $this->assertSame(DatabaseFailureCategory::Constraint, $translator->translate(DatabaseOperation::Execute, null, new TranslatorPdo(['23505', null, null]))->category());
        $this->assertSame(DatabaseFailureCategory::Statement, $translator->translate(DatabaseOperation::Execute, null, new TranslatorPdo(['42000', null, null]))->category());
        $this->assertSame(DatabaseFailureCategory::Transaction, $translator->translate(DatabaseOperation::TransactionCommit, null, new TranslatorPdo([null, null, null]))->category());
    }

    public function testTranslatorBoundsDriverSqlstateAndVendorCode(): void
    {
        $exception = (new PdoFailureTranslator())->translate(
            DatabaseOperation::Query,
            null,
            new TranslatorPdo(['not-a-sqlstate', str_repeat('9', 80), null], str_repeat('x', 80)),
            null,
            new RuntimeException('opaque'),
        );

        $this->assertNull($exception->driverName());
        $this->assertNull($exception->sqlState());
        $this->assertNull($exception->vendorCode());
    }
}

final class TranslatorPdo extends PDO
{
    /**
     * @param array<int, mixed> $errorInfo
     */
    public function __construct(private array $errorInfo, private mixed $driverName = 'sqlite') {}

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
            return $this->driverName;
        }

        return null;
    }
}
