<?php

declare(strict_types=1);

namespace Evolve\Database\Contracts\Tests\Unit;

use Evolve\Database\Contracts\DatabaseStatement;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DatabaseStatementTest extends TestCase
{
    public function test_accepts_valid_sql_without_parameters(): void
    {
        $statement = new DatabaseStatement('select 1');

        self::assertSame('select 1', $statement->sql());
        self::assertSame([], $statement->parameters());
        self::assertNull($statement->operationName());
    }

    public function test_preserves_exact_sql(): void
    {
        $sql = " select *\nfrom users where id = :id ";

        self::assertSame($sql, (new DatabaseStatement($sql, ['id' => 5]))->sql());
    }

    public function test_preserves_positional_parameters(): void
    {
        $parameters = [1, null, true, 1.5, 'active'];

        self::assertSame($parameters, (new DatabaseStatement('select ?', $parameters))->parameters());
    }

    public function test_preserves_named_parameters(): void
    {
        $parameters = ['user_id' => 12, 'status' => 'active'];

        self::assertSame($parameters, (new DatabaseStatement('select :user_id', $parameters))->parameters());
    }

    public function test_accepts_scalar_and_null_parameter_values(): void
    {
        $parameters = ['a' => null, 'b' => false, 'c' => 1, 'd' => 2.5, 'e' => 'value'];

        self::assertSame($parameters, (new DatabaseStatement('select :a', $parameters))->parameters());
    }

    public function test_rejects_blank_sql(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatabaseStatement(" \n\t ");
    }

    public function test_rejects_object_parameter_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatabaseStatement('select :value', ['value' => new \stdClass()]);
    }

    public function test_rejects_array_parameter_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatabaseStatement('select :value', ['value' => ['nested']]);
    }

    public function test_rejects_resource_parameter_value(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        try {
            new DatabaseStatement('select :value', ['value' => $resource]);
            self::fail('Resource parameter should be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        } finally {
            fclose($resource);
        }
    }

    public function test_rejects_mixed_positional_and_named_parameters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatabaseStatement('select ?', [0 => 1, 'name' => 'value']);
    }

    public function test_rejects_invalid_named_parameter_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatabaseStatement('select :bad', ['bad-key' => 'value']);
    }

    public function test_accepts_valid_operation_name(): void
    {
        $statement = new DatabaseStatement('select 1', [], 'billing.invoice.find');

        self::assertSame('billing.invoice.find', $statement->operationName());
    }

    public function test_accepts_null_operation_name(): void
    {
        self::assertNull((new DatabaseStatement('select 1', [], null))->operationName());
    }

    public function test_rejects_empty_operation_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatabaseStatement('select 1', [], '');
    }

    public function test_rejects_operation_name_longer_than_128_ascii_bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatabaseStatement('select 1', [], str_repeat('a', 129));
    }

    public function test_rejects_invalid_operation_name_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatabaseStatement('select 1', [], "billing invoice\nfind");
    }

    public function test_is_immutable_and_stable(): void
    {
        $parameters = ['id' => 1];
        $statement = new DatabaseStatement('select :id', $parameters, 'users.find');
        $parameters['id'] = 2;
        $returned = $statement->parameters();
        $returned['id'] = 3;

        self::assertSame(['id' => 1], $statement->parameters());
        self::assertSame('select :id', $statement->sql());
        self::assertSame('users.find', $statement->operationName());

        $class = new ReflectionClass(DatabaseStatement::class);
        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }
}
