<?php

declare(strict_types=1);

namespace Evolve\Database\Contracts;

use InvalidArgumentException;

/**
 * Immutable SQL statement plus bounded logical operation metadata.
 *
 * @experimental EvolvePHP 2 is pre-beta; this database contract may change before stable release.
 */
final readonly class DatabaseStatement
{
    /**
     * @param array<array-key, mixed> $parameters
     */
    public function __construct(
        private string $sql,
        private array $parameters = [],
        private ?string $operationName = null,
    ) {
        $this->assertSqlIsNotBlank($sql);
        $this->assertParametersArePortable($parameters);
        $this->assertOperationNameIsPortable($operationName);
    }

    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * @return array<array-key, null|bool|int|float|string>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function operationName(): ?string
    {
        return $this->operationName;
    }

    private function assertSqlIsNotBlank(string $sql): void
    {
        if (trim($sql) === '') {
            throw new InvalidArgumentException('Database SQL must not be blank.');
        }
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private function assertParametersArePortable(array $parameters): void
    {
        if ($parameters === []) {
            return;
        }

        if (!array_is_list($parameters)) {
            foreach ($parameters as $key => $value) {
                if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                    throw new InvalidArgumentException('Database named parameter keys must use conservative identifier tokens.');
                }

                $this->assertParameterValueIsPortable($value);
            }

            return;
        }

        foreach ($parameters as $value) {
            $this->assertParameterValueIsPortable($value);
        }
    }

    private function assertParameterValueIsPortable(mixed $value): void
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return;
        }

        throw new InvalidArgumentException('Database statement parameters may contain only null, bool, int, float or string values.');
    }

    private function assertOperationNameIsPortable(?string $operationName): void
    {
        if ($operationName === null) {
            return;
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $operationName) !== 1) {
            throw new InvalidArgumentException('Database operation names must use a bounded conservative token syntax.');
        }
    }
}
