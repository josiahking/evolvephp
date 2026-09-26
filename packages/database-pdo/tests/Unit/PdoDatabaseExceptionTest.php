<?php

declare(strict_types=1);

namespace Evolve\Database\Pdo\Tests\Unit;

use Evolve\Contracts\Exception\EvolveException;
use Evolve\Database\Contracts\DatabaseFailureCategory;
use Evolve\Database\Contracts\DatabaseOperation;
use Evolve\Database\Contracts\Exception\DatabaseException;
use Evolve\Database\Pdo\Exception\PdoDatabaseException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdoDatabaseExceptionTest extends TestCase
{
    public function testExceptionExposesBoundedDatabaseFailureMetadata(): void
    {
        $previous = new RuntimeException('driver failed with sensitive text');
        $primary = new LogicException('callback failed');

        $exception = new PdoDatabaseException(
            DatabaseOperation::Execute,
            DatabaseFailureCategory::Statement,
            'persist-user',
            'sqlite',
            '23000',
            '19',
            $previous,
            $primary,
        );

        $this->assertContains(DatabaseException::class, class_implements(PdoDatabaseException::class));
        $this->assertContains(EvolveException::class, class_implements(PdoDatabaseException::class));
        $this->assertSame(DatabaseOperation::Execute, $exception->operation());
        $this->assertSame(DatabaseFailureCategory::Statement, $exception->category());
        $this->assertSame('persist-user', $exception->operationName());
        $this->assertSame('sqlite', $exception->driverName());
        $this->assertSame('23000', $exception->sqlState());
        $this->assertSame('19', $exception->vendorCode());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame($primary, $exception->primaryFailure());
        $this->assertStringNotContainsString('sensitive text', $exception->getMessage());
        $this->assertStringNotContainsString('callback failed', $exception->getMessage());
    }

    public function testPrimaryFailureCanBeAbsent(): void
    {
        $exception = new PdoDatabaseException(DatabaseOperation::Query, DatabaseFailureCategory::Unknown);

        $this->assertNull($exception->primaryFailure());
        $this->assertNull($exception->operationName());
        $this->assertNull($exception->driverName());
        $this->assertNull($exception->sqlState());
        $this->assertNull($exception->vendorCode());
        $this->assertNull($exception->getPrevious());
    }
}
