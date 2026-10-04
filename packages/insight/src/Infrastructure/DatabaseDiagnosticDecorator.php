<?php

declare(strict_types=1);

namespace Evolve\Insight\Infrastructure;

use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseOperation;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Database\Contracts\Exception\DatabaseException;

final readonly class DatabaseDiagnosticDecorator implements DatabaseConnection
{
    public function __construct(
        private DatabaseConnection $connection,
        private DiagnosticRecorder $recorder,
        private DatabaseDiagnosticPolicy $policy = new DatabaseDiagnosticPolicy(),
    ) {}

    public function execute(DatabaseStatement $statement): int
    {
        return $this->recorder->run(
            'database',
            'execute',
            fn(): int => $this->connection->execute($statement),
            fn(int $affected): array => $this->metadata($statement, 'execute') + ['affected_rows' => $affected],
            fn(\Throwable $failure): array => array_replace($this->metadata($statement, 'execute'), $this->failure($failure)),
            $this->policy->slowThresholdNanoseconds,
        );
    }

    public function query(DatabaseStatement $statement): iterable
    {
        return $this->recorder->run(
            'database',
            'query',
            fn(): iterable => $this->connection->query($statement),
            fn(iterable $rows): array => $this->metadata($statement, 'query'),
            fn(\Throwable $failure): array => array_replace($this->metadata($statement, 'query'), $this->failure($failure)),
            $this->policy->slowThresholdNanoseconds,
        );
    }

    public function transaction(callable $operation): mixed
    {
        return $this->recorder->run(
            'database',
            'transaction',
            fn(): mixed => $this->connection->transaction(
                fn(DatabaseConnection $active): mixed => $operation(new self($active, $this->recorder, $this->policy)),
            ),
            static fn(mixed $result): array => [],
            fn(\Throwable $failure): array => $this->failure($failure),
        );
    }

    /** @return array<string, string|int|bool> */
    private function metadata(DatabaseStatement $statement, string $operationKind): array
    {
        $fingerprint = substr(hash('sha256', $statement->sql()), 0, 32);
        $parameters = $statement->parameters();
        $types = array_map(static fn(mixed $value): string => get_debug_type($value), array_values($parameters));
        $types = array_unique($types);
        sort($types);
        $metadata = [
            'fingerprint' => $fingerprint,
            'parameter_count' => count($parameters),
            'parameter_types' => implode(',', $types),
        ];
        $repeatGroup = hash('sha256', $operationKind . "\0" . $fingerprint . "\0" . ($statement->operationName() ?? ''));
        $repeat = $this->recorder->correlation()->repeat($repeatGroup);
        if ($repeat !== null) {
            $metadata['repeat_occurrence'] = $repeat;
            if ($repeat >= $this->policy->repeatThreshold) {
                $metadata['repeated'] = true;
            }
        }
        if ($statement->operationName() !== null) {
            $metadata['operation_name'] = $statement->operationName();
        }
        if ($this->policy->captureSql) {
            $metadata['sql'] = substr($statement->sql(), 0, $this->policy->maximumSqlLength);
            $metadata['sql_truncated'] = strlen($statement->sql()) > $this->policy->maximumSqlLength;
        }

        return $metadata;
    }

    /** @return array<string, string|int> */
    private function failure(\Throwable $failure): array
    {
        if (!$failure instanceof DatabaseException) {
            return [];
        }

        $values = ['failure_category' => $failure->category()->value];
        $operation = $failure->operation();
        if (in_array($operation, [DatabaseOperation::TransactionBegin, DatabaseOperation::TransactionCommit, DatabaseOperation::TransactionRollback], true)) {
            $values['transaction_phase'] = $operation->value;
        }
        $operationName = $failure->operationName();
        if ($operationName !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $operationName) === 1) {
            $values['operation_name'] = $operationName;
        }
        $driver = $failure->driverName();
        if ($driver !== null && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $driver) === 1) {
            $values['driver'] = $driver;
        }
        $sqlState = $failure->sqlState();
        if ($sqlState !== null && preg_match('/^[A-Z0-9]{5}$/D', $sqlState) === 1) {
            $values['sqlstate'] = $sqlState;
        }
        $vendorCode = $failure->vendorCode();
        if ($vendorCode !== null && preg_match('/^[A-Za-z0-9_.:-]{1,32}$/D', (string) $vendorCode) === 1) {
            $values['vendor_code'] = (string) $vendorCode;
        }

        return $values;
    }
}
