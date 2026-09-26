<?php

declare(strict_types=1);

namespace Evolve\Database\Contracts\Tests\Unit;

use Evolve\Contracts\Exception\EvolveException;
use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseFailureCategory;
use Evolve\Database\Contracts\DatabaseOperation;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Database\Contracts\Exception\DatabaseException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

final class DatabaseConnectionContractTest extends TestCase
{
    public function test_connection_interface_exposes_only_the_accepted_operation_family(): void
    {
        $contract = new ReflectionClass(DatabaseConnection::class);

        self::assertTrue($contract->isInterface());
        self::assertSame(['execute', 'query', 'transaction'], $this->publicMethodNames($contract));

        $this->assertMethodSignature($contract->getMethod('execute'), DatabaseStatement::class, 'int');
        $this->assertMethodSignature($contract->getMethod('query'), DatabaseStatement::class, 'iterable');
        $this->assertMethodSignature($contract->getMethod('transaction'), 'callable', 'mixed');
    }

    public function test_transaction_phpdoc_documents_callback_commit_rollback_and_nested_semantics(): void
    {
        $docComment = (new ReflectionClass(DatabaseConnection::class))->getMethod('transaction')->getDocComment();

        self::assertIsString($docComment);

        foreach ([
            '@template',
            'DatabaseConnection',
            'begins before invoking',
            'commit',
            'callback result is',
            'returned unchanged',
            'rollback',
            'original throwable',
            'nested',
            'DatabaseException',
            'Savepoint',
        ] as $needle) {
            self::assertStringContainsString($needle, $docComment);
        }
    }

    public function test_query_and_transaction_phpdoc_document_lazy_iterable_transaction_lifetime(): void
    {
        $contract = new ReflectionClass(DatabaseConnection::class);
        $queryDoc = $contract->getMethod('query')->getDocComment();
        $transactionDoc = $contract->getMethod('transaction')->getDocComment();

        self::assertIsString($queryDoc);
        self::assertIsString($transactionDoc);

        foreach ([
            'iterable may be lazy',
            'does not extend transaction lifetime',
            'commit or rollback is controlled solely',
            'callback returning or throwing',
            'fully consume the iterable inside the transaction callback',
            'must not keep a transaction silently open',
        ] as $needle) {
            self::assertStringContainsString($needle, $queryDoc);
        }

        foreach ([
            'does not prolong or defer transaction completion',
            'callback returns normally',
            'commits before transaction() itself returns',
        ] as $needle) {
            self::assertStringContainsString($needle, $transactionDoc);
        }
    }

    public function test_database_operation_contains_exact_bounded_cases_and_values(): void
    {
        self::assertSame(
            [
                'Execute' => 'execute',
                'Query' => 'query',
                'TransactionBegin' => 'transaction.begin',
                'TransactionCommit' => 'transaction.commit',
                'TransactionRollback' => 'transaction.rollback',
            ],
            $this->enumNamesAndValues(DatabaseOperation::class),
        );
    }

    public function test_database_failure_category_contains_exact_bounded_cases_and_values(): void
    {
        self::assertSame(
            [
                'Connection' => 'connection',
                'Authentication' => 'authentication',
                'Constraint' => 'constraint',
                'Timeout' => 'timeout',
                'Deadlock' => 'deadlock',
                'Serialization' => 'serialization',
                'Transaction' => 'transaction',
                'Statement' => 'statement',
                'Driver' => 'driver',
                'Unknown' => 'unknown',
            ],
            $this->enumNamesAndValues(DatabaseFailureCategory::class),
        );
    }

    public function test_database_exception_extends_evolve_exception_and_exposes_safe_diagnostics(): void
    {
        $contract = new ReflectionClass(DatabaseException::class);

        self::assertTrue($contract->isInterface());
        self::assertTrue($contract->implementsInterface(EvolveException::class));
        self::assertSame(
            ['category', 'driverName', 'operation', 'operationName', 'sqlState', 'vendorCode'],
            array_values(array_filter(
                $this->publicMethodNames($contract),
                static fn(string $method): bool => !in_array($method, [
                    '__toString',
                    'getCode',
                    'getFile',
                    'getLine',
                    'getMessage',
                    'getPrevious',
                    'getTrace',
                    'getTraceAsString',
                ], true),
            )),
        );

        $this->assertReturnType($contract->getMethod('operation'), DatabaseOperation::class);
        $this->assertReturnType($contract->getMethod('category'), DatabaseFailureCategory::class);
        $this->assertReturnType($contract->getMethod('operationName'), '?string');
        $this->assertReturnType($contract->getMethod('driverName'), '?string');
        $this->assertReturnType($contract->getMethod('sqlState'), '?string');
        $this->assertReturnType($contract->getMethod('vendorCode'), 'int|string|null');
    }

    /**
     * @template T of object
     *
     * @param ReflectionClass<T> $contract
     *
     * @return list<string>
     */
    private function publicMethodNames(ReflectionClass $contract): array
    {
        $names = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $contract->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($names);

        return $names;
    }

    private function assertMethodSignature(ReflectionMethod $method, string $parameterType, string $returnType): void
    {
        $parameters = $method->getParameters();

        self::assertCount(1, $parameters);
        self::assertInstanceOf(ReflectionNamedType::class, $parameters[0]->getType());
        self::assertSame($parameterType, $parameters[0]->getType()->getName());

        $this->assertReturnType($method, $returnType);
    }

    private function assertReturnType(ReflectionMethod $method, string $expected): void
    {
        $type = $method->getReturnType();

        if ($expected === 'int|string|null') {
            self::assertInstanceOf(ReflectionUnionType::class, $type);

            $names = array_map(
                static fn(ReflectionNamedType $unionType): string => $unionType->getName(),
                $type->getTypes(),
            );
            sort($names);

            self::assertSame(['int', 'null', 'string'], $names);

            return;
        }

        self::assertInstanceOf(ReflectionNamedType::class, $type);

        $actual = $type->allowsNull() && $type->getName() !== 'mixed'
            ? '?' . $type->getName()
            : $type->getName();

        self::assertSame($expected, $actual);
    }

    /**
     * @param class-string $enum
     *
     * @return array<string, string>
     */
    private function enumNamesAndValues(string $enum): array
    {
        $cases = [];

        foreach ($enum::cases() as $case) {
            $cases[$case->name] = $case->value;
        }

        return $cases;
    }
}
