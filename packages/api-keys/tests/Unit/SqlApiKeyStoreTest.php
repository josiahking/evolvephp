<?php

declare(strict_types=1);

namespace Evolve\ApiKeys\Tests\Unit;

use DateTimeImmutable;
use Evolve\ApiKeys\ApiKeyRecord;
use Evolve\ApiKeys\ApiKeysMigrationContributor;
use Evolve\ApiKeys\SqlApiKeyStore;
use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Database\Pdo\PdoDatabaseConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SqlApiKeyStoreTest extends TestCase
{
    public function testDuplicateIdentifierFailsAndRevocationIsConditional(): void
    {
        $db = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
        foreach ((new ApiKeysMigrationContributor())->definitions() as $definition) {
            $definition->action()->invoke($db);
        }
        $store = new SqlApiKeyStore($db);
        $record = new ApiKeyRecord('key-1', str_repeat('a', 64), 'user-1', 'tenant-a', ['read'], new DateTimeImmutable('2030-01-01'));
        $store->insert($record);
        self::assertNotNull($store->find('key-1', 'user-1', 'tenant-a'));
        self::assertNull($store->find('key-1', 'user-1', 'tenant-b'));
        try {
            $store->insert($record);
            self::fail('Expected duplicate identifier failure.');
        } catch (\Evolve\Database\Contracts\Exception\DatabaseException) {
        }
        self::assertTrue($store->revoke('key-1', 'user-1', 'tenant-a', new DateTimeImmutable('2029-01-01')));
        self::assertFalse($store->revoke('key-1', 'user-1', 'tenant-a', new DateTimeImmutable('2029-01-01')));
    }

    public function testStorageAndTransactionFailureRemainVisible(): void
    {
        $failure = new RuntimeException('unavailable');
        $db = new class ($failure) implements DatabaseConnection {
            public function __construct(private RuntimeException $failure) {}
            public function execute(DatabaseStatement $statement): int
            {
                throw $this->failure;
            }
            public function query(DatabaseStatement $statement): iterable
            {
                throw $this->failure;
            }
            public function transaction(callable $operation): mixed
            {
                throw $this->failure;
            }
        };
        $store = new SqlApiKeyStore($db);
        try {
            $store->insert(new ApiKeyRecord('key-1', str_repeat('a', 64), 'user-1', 'tenant-a', ['read'], new DateTimeImmutable('2030-01-01')));
            self::fail('Expected transaction failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testCrossTenantStorageRowIsRejected(): void
    {
        $db = new class implements DatabaseConnection {
            public function execute(DatabaseStatement $statement): int
            {
                return 0;
            }
            public function query(DatabaseStatement $statement): iterable
            {
                yield ['identifier' => 'key-1', 'principal' => 'user-1', 'tenant' => 'tenant-b'];
            }
            public function transaction(callable $operation): mixed
            {
                return $operation($this);
            }
        };
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('integrity failure');
        (new SqlApiKeyStore($db))->find('key-1', 'user-1', 'tenant-a');
    }

    public function testFindUsesBoundIdentifiersEvenForUntrustedStoreCallers(): void
    {
        $db = new class implements DatabaseConnection {
            public ?DatabaseStatement $statement = null;
            public function execute(DatabaseStatement $statement): int
            {
                return 0;
            }
            public function query(DatabaseStatement $statement): iterable
            {
                $this->statement = $statement;
                return [];
            }
            public function transaction(callable $operation): mixed
            {
                return $operation($this);
            }
        };
        (new SqlApiKeyStore($db))->find("id' OR 1=1", 'user-1', 'tenant-a');
        self::assertNotNull($db->statement);
        self::assertStringNotContainsString("id' OR 1=1", $db->statement->sql());
        self::assertSame("id' OR 1=1", $db->statement->parameters()['id']);
    }

    public function testOtherStoreObservesCommittedRevocationOnNextRead(): void
    {
        $db = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
        foreach ((new ApiKeysMigrationContributor())->definitions() as $definition) {
            $definition->action()->invoke($db);
        }
        $writer = new SqlApiKeyStore($db);
        $reader = new SqlApiKeyStore($db);
        $writer->insert(new ApiKeyRecord('key-1', str_repeat('a', 64), 'user-1', 'tenant-a', ['read'], new DateTimeImmutable('2030-01-01')));
        self::assertNull($reader->find('key-1', 'user-1', 'tenant-a')?->revokedAt());
        self::assertTrue($writer->revoke('key-1', 'user-1', 'tenant-a', new DateTimeImmutable('2029-01-01')));
        self::assertNotNull($reader->find('key-1', 'user-1', 'tenant-a')?->revokedAt());
    }

    public function testCorruptPersistedTimestampsAndMissingFieldsFailClosed(): void
    {
        $valid = [
            'identifier' => 'key-1',
            'digest' => str_repeat('a', 64),
            'principal' => 'user-1',
            'tenant' => 'tenant-a',
            'scopes_json' => '["read"]',
            'expires_at' => '2030-01-01T00:00:00.000000Z',
            'revoked_at' => null,
        ];
        $cases = [];
        foreach (['', '2030-02-30T00:00:00.000000Z', '2030-01-01T00:00:00Z', 'not-a-date', 123, null] as $value) {
            $cases[] = array_replace($valid, ['expires_at' => $value]);
            if ($value !== null) {
                $cases[] = array_replace($valid, ['revoked_at' => $value]);
            }
        }
        foreach (array_keys($valid) as $field) {
            $row = $valid;
            unset($row[$field]);
            $cases[] = $row;
        }
        foreach ($cases as $row) {
            $db = new class ($row) implements DatabaseConnection {
                /** @param array<string, mixed> $row */
                public function __construct(private array $row) {}
                public function execute(DatabaseStatement $statement): int
                {
                    return 0;
                }
                public function query(DatabaseStatement $statement): iterable
                {
                    return [$this->row];
                }
                public function transaction(callable $operation): mixed
                {
                    return $operation($this);
                }
            };
            $rejected = false;
            try {
                (new SqlApiKeyStore($db))->find('key-1', 'user-1', 'tenant-a');
            } catch (RuntimeException $failure) {
                $rejected = true;
                self::assertSame('API key storage integrity failure.', $failure->getMessage());
            }
            self::assertTrue($rejected, 'Corrupt persisted row must fail closed: ' . json_encode($row));
        }
    }

    public function testSqliteRollbackKeepsStorageEmpty(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $db = new PdoDatabaseConnection($pdo);
        foreach ((new ApiKeysMigrationContributor())->definitions() as $definition) {
            $definition->action()->invoke($db);
        }
        $threw = false;
        try {
            $db->transaction(static function (DatabaseConnection $connection): void {
                if ($connection->execute(new DatabaseStatement(
                    'INSERT INTO evolve_api_keys (identifier, digest, principal, tenant, scopes_json, expires_at, revoked_at) VALUES (:id, :digest, :principal, :tenant, :scopes, :expires, NULL)',
                    ['id' => 'key-1', 'digest' => str_repeat('a', 64), 'principal' => 'user-1', 'tenant' => 'tenant-a', 'scopes' => '["read"]', 'expires' => '2030-01-01T00:00:00.000000Z'],
                )) === 1) {
                    throw new RuntimeException('rollback sentinel');
                }
            });
        } catch (RuntimeException $failure) {
            $threw = true;
            self::assertSame('rollback sentinel', $failure->getMessage());
        }
        self::assertTrue($threw, 'Expected transaction rollback.');
        self::assertNull((new SqlApiKeyStore($db))->find('key-1', 'user-1', 'tenant-a'));
    }

    public function testIndependentConnectionsSeeRevocationAndFreshVerificationDenies(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'evolve-api-keys-');
        self::assertNotFalse($file);
        try {
            $writerDb = new PdoDatabaseConnection(new PDO('sqlite:' . $file));
            $readerDb = new PdoDatabaseConnection(new PDO('sqlite:' . $file));
            foreach ((new ApiKeysMigrationContributor())->definitions() as $definition) {
                $definition->action()->invoke($writerDb);
            }
            $writer = new SqlApiKeyStore($writerDb);
            $reader = new SqlApiKeyStore($readerDb);
            $writer->insert(new ApiKeyRecord(str_repeat('0', 32), hash('sha256', str_repeat('1', 64)), 'user-1', 'tenant-a', ['read'], new DateTimeImmutable('2030-01-01')));
            self::assertNull($reader->find(str_repeat('0', 32), 'user-1', 'tenant-a')?->revokedAt());
            $credential = 'ak1.' . str_repeat('0', 32) . '.' . str_repeat('1', 64);
            $request = new \Evolve\ApiKeys\ApiKeyVerificationRequest($credential, 'user-1', 'tenant-a', ['read'], new DateTimeImmutable('2029-01-01'));
            $policy = new class implements \Evolve\ApiKeys\ApiKeyAccessPolicy {
                public function canIssue(string $actor, string $principal, string $tenant, array $scopes): bool
                {
                    return false;
                }
                public function canRevoke(string $actor, string $principal, string $tenant): bool
                {
                    return false;
                }
            };
            $keys = new \Evolve\ApiKeys\ApiKeys($reader, $policy);
            self::assertTrue($writer->revoke(str_repeat('0', 32), 'user-1', 'tenant-a', new DateTimeImmutable('2029-01-01')));
            self::assertFalse($writer->revoke(str_repeat('0', 32), 'user-1', 'tenant-a', new DateTimeImmutable('2029-01-01')));
            self::assertNotNull($reader->find(str_repeat('0', 32), 'user-1', 'tenant-a')?->revokedAt());
            self::assertFalse($keys->verify($request)->allowed());
        } finally {
            unset($keys, $writer, $reader, $writerDb, $readerDb);
            gc_collect_cycles();
            unlink($file);
        }
    }
}
