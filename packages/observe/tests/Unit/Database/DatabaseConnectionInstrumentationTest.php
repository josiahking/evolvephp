<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Database;

use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Observe\Database\DatabaseConnectionInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseConnectionInstrumentationTest extends TestCase
{
    public function testDisabledPreservesLazyQueryIdentityAndDelegation(): void
    {
        $rows = (static function (): \Generator {
            yield ['private' => 'value'];
        })();
        $connection = new class ($rows) implements DatabaseConnection {
            public int $queries = 0;
            /** @param iterable<array-key, mixed> $rows */
            public function __construct(private iterable $rows) {}
            public function execute(DatabaseStatement $statement): int
            {
                return 7;
            }
            public function query(DatabaseStatement $statement): iterable
            {
                ++$this->queries;
                return $this->rows;
            }
            public function transaction(callable $operation): mixed
            {
                return $operation($this);
            }
        };
        $decorator = new DatabaseConnectionInstrumentation(OpenTelemetryComposition::disabled(), $connection);
        self::assertSame(7, $decorator->execute(new DatabaseStatement('UPDATE t SET x=1')));
        self::assertSame($rows, $decorator->query(new DatabaseStatement('SELECT * FROM t')));
        self::assertSame(1, $connection->queries);
    }

    public function testTransactionUsesOwningConnectionAndDecoratesActiveConnection(): void
    {
        $active = new class implements DatabaseConnection {
            public int $executions = 0;
            public function execute(DatabaseStatement $statement): int
            {
                ++$this->executions;
                return 3;
            }
            public function query(DatabaseStatement $statement): iterable
            {
                return [];
            }
            public function transaction(callable $operation): mixed
            {
                return $operation($this);
            }
        };
        $owner = new class ($active) implements DatabaseConnection {
            public int $transactions = 0;
            public function __construct(private DatabaseConnection $active) {}
            public function execute(DatabaseStatement $statement): int
            {
                throw new RuntimeException('wrong connection');
            }
            public function query(DatabaseStatement $statement): iterable
            {
                throw new RuntimeException('wrong connection');
            }
            public function transaction(callable $operation): mixed
            {
                ++$this->transactions;
                return $operation($this->active);
            }
        };
        $decorator = new DatabaseConnectionInstrumentation(OpenTelemetryComposition::disabled(), $owner);
        self::assertSame(3, $decorator->transaction(static fn(DatabaseConnection $connection): int => $connection->execute(new DatabaseStatement('UPDATE t SET x=1'))));
        self::assertSame(1, $owner->transactions);
        self::assertSame(1, $active->executions);
    }
    public function testDisabledTransactionPassesExactActiveConnectionAndResult(): void
    {
        $active = $this->createStub(DatabaseConnection::class);
        $result = new \stdClass();
        $owner = new class ($active) implements DatabaseConnection {
            public int $transactions = 0;
            public function __construct(private DatabaseConnection $active) {}
            public function execute(DatabaseStatement $statement): int
            {
                throw new RuntimeException('unexpected execute');
            }
            public function query(DatabaseStatement $statement): iterable
            {
                throw new RuntimeException('unexpected query');
            }
            public function transaction(callable $operation): mixed
            {
                ++$this->transactions;
                return $operation($this->active);
            }
        };

        $decorator = new DatabaseConnectionInstrumentation(OpenTelemetryComposition::disabled(), $owner);
        self::assertSame($result, $decorator->transaction(static function (DatabaseConnection $received) use ($active, $result): object {
            self::assertSame($active, $received);
            return $result;
        }));
        self::assertSame(1, $owner->transactions);
    }

    public function testTransactionExceptionOperationRefinesSpanButNotMetric(): void
    {
        $failure = new class extends RuntimeException implements \Evolve\Database\Contracts\Exception\DatabaseException {
            public function operation(): \Evolve\Database\Contracts\DatabaseOperation
            {
                return \Evolve\Database\Contracts\DatabaseOperation::TransactionCommit;
            }
            public function category(): \Evolve\Database\Contracts\DatabaseFailureCategory
            {
                return \Evolve\Database\Contracts\DatabaseFailureCategory::Driver;
            }
            public function operationName(): ?string
            {
                return null;
            }
            public function driverName(): ?string
            {
                return null;
            }
            public function sqlState(): ?string
            {
                return null;
            }
            public function vendorCode(): int|string|null
            {
                return null;
            }
        };
        $exporter = new \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter();
        $meters = new \Evolve\Observe\Tests\Unit\RecordingMeterProvider();
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'database-operation-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new \OpenTelemetry\SDK\Trace\TracerProvider(
                new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter),
                resource: $resource,
            ),
            meterProvider: $meters,
            resource: $resource,
        );
        $owner = new class ($failure) implements DatabaseConnection {
            public int $transactions = 0;
            public function __construct(private RuntimeException $failure) {}
            public function execute(DatabaseStatement $statement): int
            {
                return 0;
            }
            public function query(DatabaseStatement $statement): iterable
            {
                return [];
            }
            public function transaction(callable $operation): mixed
            {
                ++$this->transactions;
                throw $this->failure;
            }
        };

        try {
            (new DatabaseConnectionInstrumentation($composition, $owner))->transaction(static fn(DatabaseConnection $active): mixed => null);
            self::fail('Expected transaction failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(1, $owner->transactions);
        self::assertSame(
            'transaction.commit',
            $exporter->getSpans()[0]->getAttributes()->get(\Evolve\Observe\EvolveSemanticConventions::ATTRIBUTE_DATABASE_OPERATION),
        );
        self::assertSame(
            ['evolve.database.operation' => 'transaction'],
            $meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_DATABASE_FAILURES)->records[0]['attributes'],
        );
    }
    public function testEnabledMetricsAndSpansPreserveLazyQueryAndTransactionResult(): void
    {
        $exporter = new \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter();
        $meters = new \Evolve\Observe\Tests\Unit\RecordingMeterProvider();
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'database-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new \OpenTelemetry\SDK\Trace\TracerProvider(
                new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter),
                resource: $resource,
            ),
            meterProvider: $meters,
            resource: $resource,
        );
        $rows = (static function (): \Generator {
            yield ['private' => 'row'];
        })();
        $connection = new class ($rows) implements DatabaseConnection {
            public int $queries = 0;
            public int $transactions = 0;
            /** @param iterable<array-key, mixed> $rows */
            public function __construct(private iterable $rows) {}
            public function execute(DatabaseStatement $statement): int
            {
                return 4;
            }
            public function query(DatabaseStatement $statement): iterable
            {
                ++$this->queries;
                return $this->rows;
            }
            public function transaction(callable $operation): mixed
            {
                ++$this->transactions;
                return $operation($this);
            }
        };
        $decorator = new DatabaseConnectionInstrumentation(
            $composition,
            $connection,
            (new \Evolve\Observe\Tests\Unit\SequenceClock(1_000_000_000, 1_100_000_000, 2_000_000_000, 2_200_000_000, 3_000_000_000, 3_300_000_000))(...),
        );
        self::assertSame(4, $decorator->execute(new DatabaseStatement('UPDATE private SET x=1', ['token' => 'secret'], 'safe.operation')));
        self::assertSame($rows, $decorator->query(new DatabaseStatement('SELECT private')));
        $value = new \stdClass();
        self::assertSame($value, $decorator->transaction(static fn(DatabaseConnection $active): object => $value));
        self::assertSame(1, $connection->queries);
        self::assertSame(1, $connection->transactions);
        self::assertCount(3, $exporter->getSpans());
        foreach ($exporter->getSpans() as $span) {
            self::assertSame(\OpenTelemetry\API\Trace\SpanKind::KIND_CLIENT, $span->getKind());
        }
        self::assertSame('safe.operation', $exporter->getSpans()[0]->getAttributes()->get(\Evolve\Observe\EvolveSemanticConventions::ATTRIBUTE_DATABASE_OPERATION_NAME));
        self::assertSame(['evolve.database.operation' => 'execute'], $meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_DATABASE_COUNT)->records[0]['attributes']);
        self::assertCount(3, $meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_DATABASE_COUNT)->records);
        self::assertSame([], $meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_DATABASE_FAILURES)->records);
        $serialized = json_encode($exporter->getSpans()[0]->getAttributes()->toArray());
        self::assertStringNotContainsString('private', $serialized);
        self::assertStringNotContainsString('secret', $serialized);
    }

    public function testApplicationThrowableIdentitySurvivesTelemetryAndSafeMetadataIsSpanOnly(): void
    {
        $failure = new class extends RuntimeException implements \Evolve\Database\Contracts\Exception\DatabaseException {
            public function operation(): \Evolve\Database\Contracts\DatabaseOperation
            {
                return \Evolve\Database\Contracts\DatabaseOperation::Execute;
            }
            public function category(): \Evolve\Database\Contracts\DatabaseFailureCategory
            {
                return \Evolve\Database\Contracts\DatabaseFailureCategory::Constraint;
            }
            public function operationName(): string
            {
                return 'safe.operation';
            }
            public function driverName(): string
            {
                return 'safe_driver';
            }
            public function sqlState(): string
            {
                return '23505';
            }
            public function vendorCode(): string
            {
                return 'secret-vendor';
            }
        };
        $exporter = new \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter();
        $meters = new \Evolve\Observe\Tests\Unit\RecordingMeterProvider();
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'database-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new \OpenTelemetry\SDK\Trace\TracerProvider(
                new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter),
                resource: $resource,
            ),
            meterProvider: $meters,
            resource: $resource,
        );
        $connection = new class ($failure) implements DatabaseConnection {
            public int $calls = 0;
            public function __construct(private RuntimeException $failure) {}
            public function execute(DatabaseStatement $statement): int
            {
                ++$this->calls;
                throw $this->failure;
            }
            public function query(DatabaseStatement $statement): iterable
            {
                return [];
            }
            public function transaction(callable $operation): mixed
            {
                return $operation($this);
            }
        };
        try {
            (new DatabaseConnectionInstrumentation($composition, $connection))->execute(new DatabaseStatement('PRIVATE SQL'));
            self::fail('Expected database failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(1, $connection->calls);
        $attributes = $exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertSame('constraint', $attributes[\Evolve\Observe\EvolveSemanticConventions::ATTRIBUTE_DATABASE_FAILURE_CATEGORY]);
        self::assertSame('23505', $attributes[\Evolve\Observe\EvolveSemanticConventions::ATTRIBUTE_DATABASE_SQLSTATE]);
        self::assertSame('safe_driver', $attributes[\Evolve\Observe\EvolveSemanticConventions::ATTRIBUTE_DATABASE_DRIVER]);
        self::assertStringNotContainsString('secret-vendor', json_encode($attributes));
        self::assertSame(['evolve.database.operation' => 'execute'], $meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_DATABASE_FAILURES)->records[0]['attributes']);
    }

    public function testEnabledTransactionDecoratesActiveConnectionForNestedOperations(): void
    {
        $meters = new \Evolve\Observe\Tests\Unit\RecordingMeterProvider();
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'database-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(enabled: true, meterProvider: $meters, resource: $resource);
        $rows = (static function (): \Generator {
            yield ['private'];
        })();
        $active = new class ($rows) implements DatabaseConnection {
            /** @param iterable<array-key, mixed> $rows */
            public function __construct(private iterable $rows) {}
            public function execute(DatabaseStatement $statement): int
            {
                return 9;
            }
            public function query(DatabaseStatement $statement): iterable
            {
                return $this->rows;
            }
            public function transaction(callable $operation): mixed
            {
                throw new RuntimeException('unexpected nested transaction');
            }
        };
        $owner = new class ($active) implements DatabaseConnection {
            public int $calls = 0;
            public function __construct(private DatabaseConnection $active) {}
            public function execute(DatabaseStatement $statement): int
            {
                throw new RuntimeException('wrong owner');
            }
            public function query(DatabaseStatement $statement): iterable
            {
                throw new RuntimeException('wrong owner');
            }
            public function transaction(callable $operation): mixed
            {
                ++$this->calls;
                return $operation($this->active);
            }
        };
        $decorator = new DatabaseConnectionInstrumentation($composition, $owner);
        $expectedResult = new \stdClass();
        $result = $decorator->transaction(static function (DatabaseConnection $transaction) use ($active, $rows, $expectedResult): object {
            self::assertNotSame($active, $transaction);
            self::assertSame(9, $transaction->execute(new DatabaseStatement('UPDATE t SET x=1')));
            self::assertSame($rows, $transaction->query(new DatabaseStatement('SELECT x FROM t')));

            return $expectedResult;
        });
        self::assertSame($expectedResult, $result);
        self::assertSame(1, $owner->calls);
        $records = $meters->meter->counter(\Evolve\Observe\EvolveSemanticConventions::METRIC_DATABASE_COUNT)->records;
        self::assertSame(['execute', 'query', 'transaction'], array_column(array_column($records, 'attributes'), 'evolve.database.operation'));
    }

    public function testInvalidDatabaseFailureMetadataIsExcludedFromSpan(): void
    {
        $failure = new class extends RuntimeException implements \Evolve\Database\Contracts\Exception\DatabaseException {
            public function operation(): \Evolve\Database\Contracts\DatabaseOperation
            {
                return \Evolve\Database\Contracts\DatabaseOperation::Query;
            }

            public function category(): \Evolve\Database\Contracts\DatabaseFailureCategory
            {
                return \Evolve\Database\Contracts\DatabaseFailureCategory::Driver;
            }

            public function operationName(): string
            {
                return 'private value with spaces';
            }

            public function driverName(): string
            {
                return 'private/driver';
            }

            public function sqlState(): string
            {
                return 'private';
            }

            public function vendorCode(): string
            {
                return 'private-vendor';
            }
        };
        $exporter = new \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter();
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'database-metadata-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: new \OpenTelemetry\SDK\Trace\TracerProvider(
                new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter),
                resource: $resource,
            ),
            resource: $resource,
        );
        $connection = new class ($failure) implements DatabaseConnection {
            public function __construct(private RuntimeException $failure) {}
            public function execute(DatabaseStatement $statement): int
            {
                return 0;
            }
            public function query(DatabaseStatement $statement): iterable
            {
                throw $this->failure;
            }
            public function transaction(callable $operation): mixed
            {
                return $operation($this);
            }
        };
        try {
            (new DatabaseConnectionInstrumentation($composition, $connection))->query(new DatabaseStatement('PRIVATE SQL'));
            self::fail('Expected database failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        $attributes = $exporter->getSpans()[0]->getAttributes()->toArray();
        self::assertArrayNotHasKey(\Evolve\Observe\EvolveSemanticConventions::ATTRIBUTE_DATABASE_OPERATION_NAME, $attributes);
        self::assertArrayNotHasKey(\Evolve\Observe\EvolveSemanticConventions::ATTRIBUTE_DATABASE_SQLSTATE, $attributes);
        self::assertArrayNotHasKey(\Evolve\Observe\EvolveSemanticConventions::ATTRIBUTE_DATABASE_DRIVER, $attributes);
        self::assertStringNotContainsString('private', json_encode($attributes));
    }

    public function testDisabledTransactionPreservesExactCallbackThrowable(): void
    {
        $active = $this->createStub(DatabaseConnection::class);
        $failure = new RuntimeException('application callback failure');
        $owner = new class ($active) implements DatabaseConnection {
            public int $transactions = 0;
            public function __construct(private DatabaseConnection $active) {}
            public function execute(DatabaseStatement $statement): int
            {
                throw new RuntimeException('unexpected execute');
            }
            public function query(DatabaseStatement $statement): iterable
            {
                throw new RuntimeException('unexpected query');
            }
            public function transaction(callable $operation): mixed
            {
                ++$this->transactions;
                return $operation($this->active);
            }
        };

        $caught = null;
        try {
            (new DatabaseConnectionInstrumentation(OpenTelemetryComposition::disabled(), $owner))
                ->transaction(static function (DatabaseConnection $received) use ($active, $failure): mixed {
                    self::assertSame($active, $received);
                    throw $failure;
                });
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }
        self::assertSame($failure, $caught);
        self::assertSame(1, $owner->transactions);
    }

    public function testTelemetrySetupFailurePreservesDatabaseResultAndThrowableWithoutDoubleDelegation(): void
    {
        $traces = $this->createStub(\OpenTelemetry\API\Trace\TracerProviderInterface::class);
        $traces->method('getTracer')->willThrowException(new RuntimeException('trace setup failure'));
        $meters = $this->createStub(\OpenTelemetry\API\Metrics\MeterProviderInterface::class);
        $meters->method('getMeter')->willThrowException(new RuntimeException('meter setup failure'));
        $resource = \OpenTelemetry\SDK\Resource\ResourceInfo::create(
            \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                \OpenTelemetry\SemConv\Attributes\ServiceAttributes::SERVICE_NAME => 'database-isolation-test',
            ]),
        );
        $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $traces, meterProvider: $meters, resource: $resource);
        $rows = (static function (): \Generator {
            yield ['private'];
        })();
        $failure = new RuntimeException('application query failure');
        $connection = new class ($rows, $failure) implements DatabaseConnection {
            public int $executions = 0;
            public int $queries = 0;
            /** @param iterable<array-key, mixed> $rows */
            public function __construct(private iterable $rows, private RuntimeException $failure) {}
            public function execute(DatabaseStatement $statement): int
            {
                ++$this->executions;
                return 7;
            }
            public function query(DatabaseStatement $statement): iterable
            {
                ++$this->queries;
                if ($this->queries === 1) {
                    return $this->rows;
                }
                throw $this->failure;
            }
            public function transaction(callable $operation): mixed
            {
                return $operation($this);
            }
        };
        $decorator = new DatabaseConnectionInstrumentation($composition, $connection);
        self::assertSame(7, $decorator->execute(new DatabaseStatement('PRIVATE SQL')));
        self::assertSame($rows, $decorator->query(new DatabaseStatement('PRIVATE SQL')));
        try {
            $decorator->query(new DatabaseStatement('PRIVATE SQL'));
            self::fail('Expected query failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(1, $connection->executions);
        self::assertSame(2, $connection->queries);
    }
}
