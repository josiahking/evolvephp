<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Infrastructure;

use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseFailureCategory;
use Evolve\Database\Contracts\DatabaseOperation;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Database\Contracts\Exception\DatabaseException;
use Evolve\Insight\Capture\DiagnosticCapturePolicy;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\Capture\DiagnosticEntrySink;
use Evolve\Insight\Infrastructure\CacheDiagnosticDecorator;
use Evolve\Insight\Infrastructure\DatabaseDiagnosticDecorator;
use Evolve\Insight\Infrastructure\DatabaseDiagnosticPolicy;
use Evolve\Insight\Infrastructure\DiagnosticRecorder;
use Evolve\Insight\Infrastructure\ExecutionCorrelation;
use Evolve\Insight\Infrastructure\HttpClientDiagnosticDecorator;
use Evolve\Insight\Infrastructure\QueuePublisherDiagnosticDecorator;
use Evolve\Insight\Infrastructure\QueueReceiverDiagnosticDecorator;
use Evolve\Insight\Infrastructure\StorageDiagnosticDecorator;
use Evolve\Queue\Contracts\Delivery;
use Evolve\Queue\Contracts\Exception\QueueException;
use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueFailureCategory;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueueOperation;
use Evolve\Queue\Contracts\QueuePublisher;
use Evolve\Queue\Contracts\QueueReceiver;
use Evolve\Storage\Contracts\Exception\StorageException;
use Evolve\Storage\Contracts\ObjectStorage;
use Evolve\Storage\Contracts\ReadableObject;
use Evolve\Storage\Contracts\StorageFailureCategory;
use Evolve\Storage\Contracts\StorageKey;
use Evolve\Storage\Contracts\StorageOperation;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

final class InfrastructureDecoratorTest extends TestCase
{
    public function testCorrelationIsExecutionLocalAndNested(): void
    {
        $correlation = new ExecutionCorrelation();
        $outer = ExecutionIdentifier::generate();
        $inner = ExecutionIdentifier::generate();
        self::assertNull($correlation->identifier());
        $context = new ExecutionContext($outer, ExecutionKind::HttpRequest);
        $weakContext = \WeakReference::create($context);
        $outerAttachment = $correlation->attach($context);
        unset($context);
        self::assertNull($weakContext->get());
        self::assertTrue($outer->value() === $correlation->identifier());
        $innerAttachment = $correlation->attach(new ExecutionContext($inner, ExecutionKind::HttpRequest));
        self::assertSame($inner->value(), $correlation->identifier());
        $innerAttachment->detach();
        self::assertTrue($outer->value() === $correlation->identifier());
        $outerAttachment->detach();
        self::assertNull($correlation->identifier());
    }

    public function testSinkAndClockFailuresDoNotAlterPrimaryResultOrThrowable(): void
    {
        $correlation = new ExecutionCorrelation();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        $sink = new class implements DiagnosticEntrySink {
            public function capture(DiagnosticEntry $candidate): void
            {
                throw new \RuntimeException('sink');
            }
        };
        $recorder = new DiagnosticRecorder($sink, $correlation, static function (): int {
            throw new \RuntimeException('clock');
        });
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('get')->willReturn('value');
        self::assertSame('value', (new CacheDiagnosticDecorator($cache, $recorder))->get('secret'));
        $failure = new \RuntimeException('primary');
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('get')->willThrowException($failure);
        try {
            (new CacheDiagnosticDecorator($cache, $recorder))->get('secret');
            self::fail('Expected primary failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        $attachment->detach();
    }

    public function testDefaultMonotonicClockRecordsDurationWithoutChangingPrimaryBehavior(): void
    {
        $correlation = new ExecutionCorrelation();
        $sink = new class implements DiagnosticEntrySink {
            /** @var list<DiagnosticEntry> */
            public array $entries = [];

            public function capture(DiagnosticEntry $candidate): void
            {
                $this->entries[] = $candidate;
            }
        };
        $recorder = new DiagnosticRecorder($sink, $correlation);
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::WorkerTask));
        $cache = $this->createMock(CacheInterface::class);
        $failure = new \RuntimeException('primary');
        $cache->expects(self::exactly(2))->method('get')->willReturnCallback(static function (string $key) use ($failure): string {
            if ($key === 'throws') {
                throw $failure;
            }

            return 'value';
        });
        $decorator = new CacheDiagnosticDecorator($cache, $recorder);
        self::assertSame('value', $decorator->get('returns'));
        try {
            $decorator->get('throws');
            self::fail('Expected primary failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertCount(2, $sink->entries);
        foreach ($sink->entries as $entry) {
            $values = self::values($entry);
            self::assertArrayHasKey('duration_ns', $values);
            self::assertTrue(is_int($values['duration_ns']) || is_float($values['duration_ns']));
            self::assertGreaterThanOrEqual(0, $values['duration_ns']);
        }
        $attachment->detach();
    }

    public function testTransactionDecoratesTheActualActiveConnectionAndPreservesResultsAndFailures(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::WorkerTask));
        $active = $this->createMock(DatabaseConnection::class);
        $rows = (static function (): \Generator {
            yield ['row' => 1];
        })();
        $active->expects(self::once())->method('execute')->willReturn(3);
        $active->expects(self::once())->method('query')->willReturn($rows);
        $outer = $this->createMock(DatabaseConnection::class);
        $outer->expects(self::exactly(2))->method('transaction')->willReturnCallback(static fn(callable $callback): mixed => $callback($active));
        $database = new DatabaseDiagnosticDecorator($outer, $recorder);
        $result = new \stdClass();
        $statement = new DatabaseStatement('SELECT 1', operationName: 'inside');
        self::assertSame($result, $database->transaction(static function (DatabaseConnection $connection) use ($statement, $rows, $result): object {
            self::assertInstanceOf(DatabaseDiagnosticDecorator::class, $connection);
            self::assertSame(3, $connection->execute($statement));
            self::assertSame($rows, $connection->query($statement));

            return $result;
        }));
        self::assertCount(3, $sink->entries);
        self::assertSame('execute', $sink->entries[0]->name());
        self::assertSame('query', $sink->entries[1]->name());
        self::assertSame('transaction', $sink->entries[2]->name());
        $failure = new \RuntimeException('callback failure');
        $caught = null;
        try {
            $database->transaction(static function (DatabaseConnection $connection) use ($failure): void {
                self::assertInstanceOf(DatabaseDiagnosticDecorator::class, $connection);
                throw $failure;
            });
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }
        self::assertSame($failure, $caught);
        self::assertCount(4, $sink->entries);
        $attachment->detach();
    }

    public function testDatabaseRepeatGroupingIncludesOperationAndOperationName(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->expects(self::exactly(3))->method('execute')->willReturn(1);
        $connection->expects(self::once())->method('query')->willReturn([]);
        $database = new DatabaseDiagnosticDecorator($connection, $recorder);
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::WorkerTask));
        $alpha = new DatabaseStatement('SELECT 1', operationName: 'alpha');
        $beta = new DatabaseStatement('SELECT 1', operationName: 'beta');
        $database->execute($alpha);
        $database->execute($alpha);
        $database->query($alpha);
        $database->execute($beta);
        self::assertSame(1, self::values($sink->entries[0])['repeat_occurrence']);
        self::assertSame(2, self::values($sink->entries[1])['repeat_occurrence']);
        self::assertSame(1, self::values($sink->entries[2])['repeat_occurrence']);
        self::assertSame(1, self::values($sink->entries[3])['repeat_occurrence']);
        self::assertSame(self::values($sink->entries[0])['fingerprint'], self::values($sink->entries[2])['fingerprint']);
        $attachment->detach();
    }

    public function testDatabaseMetadataSqlOptInThresholdRepeatAndLazyRows(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $rowProbe = new class {
            public bool $consumed = false;
        };
        $rows = (static function () use ($rowProbe): \Generator {
            $rowProbe->consumed = true;
            yield ['id' => 1];
        })();
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->expects(self::exactly(2))->method('query')->willReturn($rows);
        $policy = new DatabaseDiagnosticPolicy(100, true, 8, 2);
        $decorator = new DatabaseDiagnosticDecorator($connection, $recorder, $policy);
        $statement = new DatabaseStatement('SELECT secret FROM records WHERE id = ?', ['private'], 'lookup');
        self::assertSame($rows, $decorator->query($statement));
        self::assertCount(0, $sink->entries);
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        self::assertSame($rows, $decorator->query($statement));
        self::assertFalse($rowProbe->consumed);
        self::assertCount(1, $sink->entries);
        $values = self::values($sink->entries[0]);
        self::assertSame('lookup', $values['operation_name']);
        self::assertSame(1, $values['parameter_count']);
        self::assertSame('string', $values['parameter_types']);
        self::assertSame(1, $values['repeat_occurrence']);
        self::assertArrayNotHasKey('slow', $values);
        self::assertSame('SELECT s', $values['sql']);
        self::assertNotContains('private', $values);
        $attachment->detach();
        $second = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        $connection2 = $this->createStub(DatabaseConnection::class);
        $connection2->method('execute')->willReturn(2);
        self::assertSame(2, (new DatabaseDiagnosticDecorator($connection2, $recorder, $policy))->execute($statement));
        self::assertSame(1, self::values($sink->entries[1])['repeat_occurrence']);
        $second->detach();
    }

    public function testCacheQueueStorageAndHttpDecoratorsPreserveResults(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('has')->willReturn(true);
        self::assertTrue((new CacheDiagnosticDecorator($cache, $recorder))->has('secret-key'));
        $message = new MessageEnvelope('payload');
        $queue = new QueueName('private-queue');
        $publisher = $this->createMock(QueuePublisher::class);
        $publisher->expects(self::once())->method('publish');
        (new QueuePublisherDiagnosticDecorator($publisher, $recorder))->publish($queue, $message);
        $delivery = $this->createStub(Delivery::class);
        $delivery->method('message')->willReturn($message);
        $receiver = $this->createStub(QueueReceiver::class);
        $receiver->method('receive')->willReturn($delivery);
        $wrapped = (new QueueReceiverDiagnosticDecorator($receiver, $recorder))->receive($queue);
        self::assertNotNull($wrapped);
        self::assertSame($message, $wrapped->message());
        $wrapped->acknowledge();
        $key = new StorageKey('private-key');
        $reader = $this->createStub(ReadableObject::class);
        $reader->method('read')->willReturn('abc');
        $storage = $this->createStub(ObjectStorage::class);
        $storage->method('open')->willReturn($reader);
        $opened = (new StorageDiagnosticDecorator($storage, $recorder))->open($key);
        self::assertNotNull($opened);
        self::assertSame('abc', $opened->read(3));
        $opened->close();
        $request = $this->createStub(RequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(503);
        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturn($response);
        self::assertSame($response, (new HttpClientDiagnosticDecorator($client, $recorder))->sendRequest($request));
        self::assertCount(8, $sink->entries);
        $encoded = json_encode(array_map(self::values(...), $sink->entries), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret-key', $encoded);
        $attachment->detach();
    }

    public function testDatabaseSlowThresholdFailureMetadataAndBoundedRepeatGroups(): void
    {
        $correlation = new ExecutionCorrelation();
        $sink = new class implements DiagnosticEntrySink {
            /** @var list<DiagnosticEntry> */
            public array $entries = [];
            public function capture(DiagnosticEntry $candidate): void
            {
                $this->entries[] = $candidate;
            }
        };
        $ticks = [0, 99, 100, 200, 201, 301];
        $recorder = new DiagnosticRecorder($sink, $correlation, static function () use (&$ticks): int {
            return array_shift($ticks);
        });
        $connection = $this->createMock(DatabaseConnection::class);
        $failure = new class ('primary') extends \RuntimeException implements DatabaseException {
            public function operation(): DatabaseOperation
            {
                return DatabaseOperation::TransactionCommit;
            }
            public function category(): DatabaseFailureCategory
            {
                return DatabaseFailureCategory::Timeout;
            }
            public function operationName(): string
            {
                return 'lookup';
            }
            public function driverName(): string
            {
                return 'sqlite';
            }
            public function sqlState(): string
            {
                return 'HY000';
            }
            public function vendorCode(): int
            {
                return 42;
            }
        };
        $connection->expects(self::exactly(2))->method('execute')->willReturn(1);
        $connection->expects(self::once())->method('transaction')->willThrowException($failure);
        $database = new DatabaseDiagnosticDecorator($connection, $recorder, new DatabaseDiagnosticPolicy(100));
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::WorkerTask));
        $statement = new DatabaseStatement('select 1');
        $database->execute($statement);
        $database->execute($statement);
        self::assertArrayNotHasKey('slow', self::values($sink->entries[0]));
        self::assertTrue(self::values($sink->entries[1])['slow']);
        self::assertSame(2, self::values($sink->entries[1])['repeat_occurrence']);
        try {
            $database->transaction(static fn(DatabaseConnection $active): null => null);
            self::fail('Expected primary database failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        $failureValues = self::values($sink->entries[2]);
        self::assertSame('timeout', $failureValues['failure_category']);
        self::assertSame('transaction.commit', $failureValues['transaction_phase']);
        self::assertSame('sqlite', $failureValues['driver']);
        self::assertSame('HY000', $failureValues['sqlstate']);
        self::assertSame('42', $failureValues['vendor_code']);
        self::assertNull($correlation->repeat(str_repeat('x', 65)));
        for ($group = 0; $group < 63; ++$group) {
            self::assertSame(1, $correlation->repeat('group-' . $group));
        }
        self::assertNull($correlation->repeat('overflow'));
        for ($occurrence = 0; $occurrence < 65535; ++$occurrence) {
            $count = $correlation->repeat('group-0');
        }
        self::assertSame(65535, $count);
        $attachment->detach();
        self::assertNull($correlation->repeat('group-0'));
    }

    public function testSqlNeedsCaptureOptInAndCapturePolicyPermission(): void
    {
        $candidate = new DiagnosticEntry('execution', 'evolve.infrastructure.database', 'query', [
            new \Evolve\Insight\Capture\DiagnosticAttribute('sql', DiagnosticDataClassification::BusinessSensitivePayload, 'SELECT secret'),
            new \Evolve\Insight\Capture\DiagnosticAttribute('operation', DiagnosticDataClassification::PublicOperationalMetadata, 'query'),
        ]);
        $default = new DiagnosticCapturePolicy();
        $accepted = $default->apply($candidate);
        self::assertNotNull($accepted);
        self::assertArrayNotHasKey('sql', self::values($accepted));
        $permissive = new DiagnosticCapturePolicy(acceptedClassifications: [
            DiagnosticDataClassification::PublicOperationalMetadata,
            DiagnosticDataClassification::BusinessSensitivePayload,
        ]);
        $accepted = $permissive->apply($candidate);
        self::assertNotNull($accepted);
        self::assertSame('SELECT secret', self::values($accepted)['sql']);
        [$recorder, $correlation, $sink] = $this->recording();
        $connection = $this->createStub(DatabaseConnection::class);
        $connection->method('execute')->willReturn(1);
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::WorkerTask));
        (new DatabaseDiagnosticDecorator($connection, $recorder))->execute(new DatabaseStatement('SELECT secret'));
        self::assertArrayNotHasKey('sql', self::values($sink->entries[0]));
        $attachment->detach();
    }

    public function testDatabaseOmitsUnsafeProviderMetadata(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::WorkerTask));
        $failure = new class ('private provider message') extends \RuntimeException implements DatabaseException {
            public function operation(): DatabaseOperation
            {
                return DatabaseOperation::Query;
            }
            public function category(): DatabaseFailureCategory
            {
                return DatabaseFailureCategory::Driver;
            }
            public function operationName(): null
            {
                return null;
            }
            public function driverName(): string
            {
                return 'mysql://username:password@private-host';
            }
            public function sqlState(): string
            {
                return 'private-state';
            }
            public function vendorCode(): string
            {
                return 'token=secret';
            }
        };
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->expects(self::once())->method('execute')->willThrowException($failure);
        try {
            (new DatabaseDiagnosticDecorator($connection, $recorder))->execute(new DatabaseStatement('select 1'));
            self::fail('Expected database failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        $values = self::values($sink->entries[0]);
        self::assertSame('driver', $values['failure_category']);
        self::assertArrayNotHasKey('driver', $values);
        self::assertArrayNotHasKey('sqlstate', $values);
        self::assertArrayNotHasKey('vendor_code', $values);
        $attachment->detach();
    }

    public function testDatabaseFailureMetadataUsesOnlyTransactionPhasesAndSafeTokens(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::WorkerTask));
        $driver = str_repeat('d', 62) . ':x';
        $vendor = str_repeat('v', 30) . ':x';
        $queryFailure = new DiagnosticDatabaseFailure(DatabaseOperation::Query, 'safe.lookup', $driver, 'HY000', $vendor);
        $queryConnection = $this->createMock(DatabaseConnection::class);
        $queryConnection->expects(self::once())->method('query')->willThrowException($queryFailure);
        try {
            (new DatabaseDiagnosticDecorator($queryConnection, $recorder))->query(new DatabaseStatement('SELECT 1'));
            self::fail('Expected query failure.');
        } catch (DiagnosticDatabaseFailure $caught) {
            self::assertSame($queryFailure, $caught);
        }
        $queryValues = self::values($sink->entries[0]);
        self::assertArrayNotHasKey('transaction_phase', $queryValues);
        self::assertSame('safe.lookup', $queryValues['operation_name']);
        self::assertSame($driver, $queryValues['driver']);
        self::assertSame($vendor, $queryValues['vendor_code']);
        self::assertSame('HY000', $queryValues['sqlstate']);
        $executeFailure = new DiagnosticDatabaseFailure(DatabaseOperation::Execute, null, $driver, 'HY000', $vendor);
        $executeConnection = $this->createMock(DatabaseConnection::class);
        $executeConnection->expects(self::once())->method('execute')->willThrowException($executeFailure);
        try {
            (new DatabaseDiagnosticDecorator($executeConnection, $recorder))->execute(new DatabaseStatement('SELECT 1'));
            self::fail('Expected execute failure.');
        } catch (DiagnosticDatabaseFailure $caught) {
            self::assertSame($executeFailure, $caught);
        }
        self::assertArrayNotHasKey('transaction_phase', self::values($sink->entries[1]));
        foreach ([DatabaseOperation::TransactionBegin, DatabaseOperation::TransactionCommit, DatabaseOperation::TransactionRollback] as $phase) {
            $failure = new DiagnosticDatabaseFailure($phase, 'unsafe name!', $driver, 'HY000', $vendor);
            $connection = $this->createMock(DatabaseConnection::class);
            $connection->expects(self::once())->method('transaction')->willThrowException($failure);
            try {
                (new DatabaseDiagnosticDecorator($connection, $recorder))->transaction(static fn(DatabaseConnection $active): null => null);
                self::fail('Expected transaction failure.');
            } catch (DiagnosticDatabaseFailure $caught) {
                self::assertSame($failure, $caught);
            }
            $values = self::values($sink->entries[count($sink->entries) - 1]);
            self::assertSame($phase->value, $values['transaction_phase']);
            self::assertArrayNotHasKey('operation_name', $values);
        }
        $attachment->detach();
    }

    public function testCacheIterableMethodsDoNotPreconsumeInputsOrResults(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::WorkerTask));
        $probe = new class {
            public bool $consumed = false;
        };
        $keys = (static function () use ($probe): \Generator {
            $probe->consumed = true;
            yield 'private-key';
        })();
        $values = (static function () use ($probe): \Generator {
            $probe->consumed = true;
            yield 'private-key' => 'private-value';
        })();
        $returned = (static function (): \Generator {
            yield 'private-key' => 'private-value';
        })();
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('getMultiple')->with($keys)->willReturn($returned);
        $cache->expects(self::once())->method('setMultiple')->with($values)->willReturn(true);
        $cache->expects(self::once())->method('deleteMultiple')->with($keys)->willReturn(true);
        $decorator = new CacheDiagnosticDecorator($cache, $recorder);
        self::assertSame($returned, $decorator->getMultiple($keys));
        self::assertTrue($decorator->setMultiple($values));
        self::assertTrue($decorator->deleteMultiple($keys));
        self::assertFalse($probe->consumed);
        self::assertCount(3, $sink->entries);
        $encoded = json_encode(array_map(self::values(...), $sink->entries), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-key', $encoded);
        self::assertStringNotContainsString('private-value', $encoded);
        $attachment->detach();
    }

    public function testCacheScalarMethodsPreserveResultsAndPrimaryFailure(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::WorkerTask));
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('get')->with('private-key', 'private-default')->willReturn('private-value');
        $cache->expects(self::once())->method('set')->with('private-key', 'private-value', 60)->willReturn(true);
        $cache->expects(self::once())->method('delete')->with('private-key')->willReturn(false);
        $cache->expects(self::once())->method('clear')->willReturn(true);
        $cache->expects(self::once())->method('has')->with('private-key')->willReturn(false);
        $decorator = new CacheDiagnosticDecorator($cache, $recorder);
        self::assertSame('private-value', $decorator->get('private-key', 'private-default'));
        self::assertTrue($decorator->set('private-key', 'private-value', 60));
        self::assertFalse($decorator->delete('private-key'));
        self::assertTrue($decorator->clear());
        self::assertFalse($decorator->has('private-key'));
        $failure = new \RuntimeException('private cache message');
        $failing = $this->createMock(CacheInterface::class);
        $failing->expects(self::once())->method('get')->willThrowException($failure);
        try {
            (new CacheDiagnosticDecorator($failing, $recorder))->get('private-key');
            self::fail('Expected cache failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        $encoded = json_encode(array_map(self::values(...), $sink->entries), JSON_THROW_ON_ERROR);
        foreach (['private-key', 'private-default', 'private-value', 'private cache message'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $encoded);
        }
        $attachment->detach();
    }

    public function testQueueReceiverAndSettlementPreserveMessageAndThrowableIdentity(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::QueueMessage));
        $queue = new QueueName('private-queue-name');
        $message = new MessageEnvelope('private-payload', ['private-metadata' => 'private-value']);
        $failure = new class ('private endpoint') extends \RuntimeException implements QueueException {
            public function operation(): QueueOperation
            {
                return QueueOperation::Reject;
            }
            public function category(): QueueFailureCategory
            {
                return QueueFailureCategory::Settlement;
            }
        };
        $delivery = $this->createMock(Delivery::class);
        $delivery->expects(self::once())->method('message')->willReturn($message);
        $delivery->expects(self::once())->method('reject')->willThrowException($failure);
        $receiver = $this->createMock(QueueReceiver::class);
        $receiver->expects(self::once())->method('receive')->with($queue)->willReturn($delivery);
        $wrapped = (new QueueReceiverDiagnosticDecorator($receiver, $recorder))->receive($queue);
        self::assertNotNull($wrapped);
        self::assertSame($message, $wrapped->message());
        try {
            $wrapped->reject();
            self::fail('Expected settlement failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame('settlement', self::values($sink->entries[1])['failure_category']);
        $publisher = $this->createMock(QueuePublisher::class);
        $publisher->expects(self::once())->method('publish')->with($queue, $message);
        (new QueuePublisherDiagnosticDecorator($publisher, $recorder))->publish($queue, $message);
        $encoded = json_encode(array_map(self::values(...), $sink->entries), JSON_THROW_ON_ERROR);
        foreach (['private-queue-name', 'private-payload', 'private-metadata', 'private-value', 'private endpoint'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $encoded);
        }
        $attachment->detach();
    }

    public function testStorageDoesNotPreconsumeChunksAndPreservesReaderOwnership(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::WorkerTask));
        $key = new StorageKey('private-storage-key');
        $probe = new class {
            public bool $consumed = false;
        };
        $chunks = (static function () use ($probe): \Generator {
            $probe->consumed = true;
            yield 'private-object-bytes';
        })();
        $reader = $this->createMock(ReadableObject::class);
        $reader->expects(self::once())->method('read')->with(8)->willReturn('private-');
        $reader->expects(self::exactly(2))->method('close');
        $storage = $this->createMock(ObjectStorage::class);
        $storage->expects(self::once())->method('put')->with($key, $chunks);
        $storage->expects(self::once())->method('open')->with($key)->willReturn($reader);
        $storage->expects(self::once())->method('delete')->with($key);
        $decorator = new StorageDiagnosticDecorator($storage, $recorder);
        $decorator->put($key, $chunks);
        self::assertFalse($probe->consumed);
        $opened = $decorator->open($key);
        self::assertNotNull($opened);
        self::assertSame('private-', $opened->read(8));
        $opened->close();
        $opened->close();
        $decorator->delete($key);
        self::assertSame(8, self::values($sink->entries[2])['returned_bytes']);
        $encoded = json_encode(array_map(self::values(...), $sink->entries), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-storage-key', $encoded);
        self::assertStringNotContainsString('private-object-bytes', $encoded);
        self::assertStringNotContainsString('private-', $encoded);
        $failure = new class ('private backend address') extends \RuntimeException implements StorageException {
            public function operation(): StorageOperation
            {
                return StorageOperation::Open;
            }
            public function category(): StorageFailureCategory
            {
                return StorageFailureCategory::Unavailable;
            }
        };
        $failingStorage = $this->createMock(ObjectStorage::class);
        $failingStorage->expects(self::once())->method('open')->with($key)->willThrowException($failure);
        try {
            (new StorageDiagnosticDecorator($failingStorage, $recorder))->open($key);
            self::fail('Expected storage failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame('unavailable', self::values($sink->entries[6])['failure_category']);
        $attachment->detach();
    }

    public function testHttpTransportPreservesResponsesAndExceptionsWithoutSensitiveReads(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        $request = $this->createMock(RequestInterface::class);
        $request->expects(self::exactly(2))->method('getMethod')->willReturn('POST');
        $request->expects(self::never())->method('getUri');
        $request->expects(self::never())->method('getBody');
        $request->expects(self::never())->method('getHeaders');
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())->method('getStatusCode')->willReturn(503);
        $response->expects(self::never())->method('getBody');
        $response->expects(self::never())->method('getHeaders');
        $failure = new \RuntimeException('private URI and authorization');
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('sendRequest')->with($request)->willReturn($response);
        $decorator = new HttpClientDiagnosticDecorator($client, $recorder);
        self::assertSame($response, $decorator->sendRequest($request));
        self::assertSame(503, self::values($sink->entries[0])['status_code']);
        $throwing = $this->createMock(ClientInterface::class);
        $throwing->expects(self::once())->method('sendRequest')->with($request)->willThrowException($failure);
        try {
            (new HttpClientDiagnosticDecorator($throwing, $recorder))->sendRequest($request);
            self::fail('Expected transport exception.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame('POST', self::values($sink->entries[1])['method']);
        $encoded = json_encode(array_map(self::values(...), $sink->entries), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private URI', $encoded);
        $attachment->detach();
    }

    public function testDeferredDeliveryDoesNotRecordAgainstAnotherExecution(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $delivery = $this->createMock(Delivery::class);
        $failure = new \RuntimeException('settlement failure');
        $delivery->expects(self::once())->method('acknowledge');
        $delivery->expects(self::once())->method('reject')->willThrowException($failure);
        $receiver = $this->createMock(QueueReceiver::class);
        $receiver->expects(self::once())->method('receive')->willReturn($delivery);
        $first = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::QueueMessage));
        $wrapped = (new QueueReceiverDiagnosticDecorator($receiver, $recorder))->receive(new QueueName('private'));
        self::assertNotNull($wrapped);
        self::assertCount(1, $sink->entries);
        $first->detach();
        $second = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::QueueMessage));
        $wrapped->acknowledge();
        try {
            $wrapped->reject();
            self::fail('Expected settlement failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertCount(1, $sink->entries);
        $second->detach();
    }

    public function testDeferredReaderDoesNotRecordAgainstAnotherExecution(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $reader = $this->createMock(ReadableObject::class);
        $failure = new \RuntimeException('close failure');
        $reader->expects(self::once())->method('read')->with(4)->willReturn('data');
        $reader->expects(self::once())->method('close')->willThrowException($failure);
        $storage = $this->createMock(ObjectStorage::class);
        $storage->expects(self::once())->method('open')->willReturn($reader);
        $first = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::WorkerTask));
        $wrapped = (new StorageDiagnosticDecorator($storage, $recorder))->open(new StorageKey('private'));
        self::assertNotNull($wrapped);
        self::assertCount(1, $sink->entries);
        $first->detach();
        $second = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::WorkerTask));
        self::assertSame('data', $wrapped->read(4));
        try {
            $wrapped->close();
            self::fail('Expected close failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertCount(1, $sink->entries);
        $second->detach();
    }

    public function testWrappersCreatedWithoutCorrelationDoNotAdoptLaterExecution(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $delivery = $this->createMock(Delivery::class);
        $delivery->expects(self::once())->method('acknowledge');
        $receiver = $this->createMock(QueueReceiver::class);
        $receiver->expects(self::once())->method('receive')->willReturn($delivery);
        $wrappedDelivery = (new QueueReceiverDiagnosticDecorator($receiver, $recorder))->receive(new QueueName('private'));
        self::assertNotNull($wrappedDelivery);
        $reader = $this->createMock(ReadableObject::class);
        $reader->expects(self::once())->method('read')->with(4)->willReturn('data');
        $storage = $this->createMock(ObjectStorage::class);
        $storage->expects(self::once())->method('open')->willReturn($reader);
        $wrappedReader = (new StorageDiagnosticDecorator($storage, $recorder))->open(new StorageKey('private'));
        self::assertNotNull($wrappedReader);
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::WorkerTask));
        $wrappedDelivery->acknowledge();
        self::assertSame('data', $wrappedReader->read(4));
        self::assertCount(0, $sink->entries);
        $attachment->detach();
    }

    public function testHttpMethodAcceptsBoundedTokenAndOmitsInvalidToken(): void
    {
        [$recorder, $correlation, $sink] = $this->recording();
        $attachment = $correlation->attach(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::exactly(2))->method('sendRequest')->willReturn($response);
        $valid = $this->createMock(RequestInterface::class);
        $valid->expects(self::once())->method('getMethod')->willReturn('M-SEARCH');
        $valid->expects(self::never())->method('getUri');
        $valid->expects(self::never())->method('getHeaders');
        $valid->expects(self::never())->method('getBody');
        $invalid = $this->createStub(RequestInterface::class);
        $invalid->method('getMethod')->willReturn('GET /private');
        $decorator = new HttpClientDiagnosticDecorator($client, $recorder);
        self::assertSame($response, $decorator->sendRequest($valid));
        self::assertSame($response, $decorator->sendRequest($invalid));
        self::assertSame('M-SEARCH', self::values($sink->entries[0])['method'] ?? null);
        self::assertArrayNotHasKey('method', self::values($sink->entries[1]));
        $attachment->detach();
    }

    /** @return array{DiagnosticRecorder, ExecutionCorrelation, object} */
    private function recording(): array
    {
        $correlation = new ExecutionCorrelation();
        $sink = new class implements DiagnosticEntrySink {
            /** @var list<DiagnosticEntry> */
            public array $entries = [];
            public function capture(DiagnosticEntry $candidate): void
            {
                $this->entries[] = $candidate;
            }
        };

        return [new DiagnosticRecorder($sink, $correlation, static fn(): int => 100), $correlation, $sink];
    }

    /** @return array<string, string|int|float|bool|null> */
    private static function values(DiagnosticEntry $entry): array
    {
        $values = [];
        foreach ($entry->attributes() as $attribute) {
            $values[$attribute->name()] = $attribute->value();
        }

        return $values;
    }
}

final class DiagnosticDatabaseFailure extends \RuntimeException implements DatabaseException
{
    public function __construct(
        private DatabaseOperation $databaseOperation,
        private ?string $databaseOperationName,
        private ?string $databaseDriver,
        private ?string $databaseSqlState,
        private int|string|null $databaseVendorCode,
    ) {
        parent::__construct('primary failure');
    }

    public function operation(): DatabaseOperation
    {
        return $this->databaseOperation;
    }

    public function category(): DatabaseFailureCategory
    {
        return DatabaseFailureCategory::Driver;
    }

    public function operationName(): ?string
    {
        return $this->databaseOperationName;
    }

    public function driverName(): ?string
    {
        return $this->databaseDriver;
    }

    public function sqlState(): ?string
    {
        return $this->databaseSqlState;
    }

    public function vendorCode(): int|string|null
    {
        return $this->databaseVendorCode;
    }
}
