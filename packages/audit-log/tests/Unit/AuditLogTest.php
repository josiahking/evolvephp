<?php

declare(strict_types=1);

namespace Evolve\AuditLog\Tests\Unit;

use DateTimeImmutable;
use Evolve\AuditLog\AuditAccessPolicy;
use Evolve\AuditLog\AuditEvent;
use Evolve\AuditLog\AuditLog;
use Evolve\AuditLog\AuditLogMigrationContributor;
use Evolve\AuditLog\AuditLogModule;
use Evolve\AuditLog\AuditReadRequest;
use Evolve\AuditLog\AuditRetentionRequest;
use Evolve\AuditLog\Exception\DuplicateAuditEvent;
use Evolve\AuditLog\Exception\UnauthorizedAuditAccess;
use Evolve\AuditLog\RedactionPolicy;
use Evolve\AuditLog\SqlAuditStore;
use Evolve\Migration\MigrationRegistry;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Database\Pdo\PdoDatabaseConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditLogTest extends TestCase
{
    private function connection(): PdoDatabaseConnection
    {
        $connection = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
        $definition = iterator_to_array((new AuditLogMigrationContributor())->definitions())[0];
        $definition->action()->invoke($connection);

        return $connection;
    }

    /** @param array<string, mixed> $payload */
    private function event(string $id, string $actor, ?string $tenant, array $payload = []): AuditEvent
    {
        return new AuditEvent($id, 'user.login', new DateTimeImmutable('2026-10-29T10:00:00Z'), $actor, $tenant, 'user-1', 'corr-1', $payload);
    }

    private function policy(): AuditAccessPolicy
    {
        return new class implements AuditAccessPolicy {
            public function canRead(string $principal, ?string $tenant): bool
            {
                return $principal === 'auditor' && $tenant !== 'blocked';
            }
            public function canPurge(string $principal, ?string $tenant): bool
            {
                return $principal === 'retention-admin';
            }
        };
    }

    private function log(DatabaseConnection $connection): AuditLog
    {
        return new AuditLog(new SqlAuditStore($connection), new RedactionPolicy(['outcome', 'details']), $this->policy());
    }

    public function testWritesRedactedEventAndReadsOnlyAuthorizedTenant(): void
    {
        $connection = $this->connection();
        $log = $this->log($connection);
        $log->record($this->event('event-1', 'actor-A', 'tenant-A', [
            'outcome' => 'success',
            'password' => 'secret-sentinel',
            'details' => ['safe' => 'value', 'access_token' => 'secret-sentinel'],
        ]));
        $log->record($this->event('event-2', 'actor-B', 'tenant-B', ['outcome' => 'failure']));
        $rows = $log->read(new AuditReadRequest('auditor', 'tenant-A', 10));
        self::assertCount(1, $rows);
        self::assertSame('event-1', $rows[0]->id);
        self::assertSame('actor-A', $rows[0]->actorId);
        self::assertSame(['outcome' => 'success', 'details' => ['safe' => 'value']], $rows[0]->payload);
        $raw = iterator_to_array($connection->query(new DatabaseStatement('SELECT payload_json FROM evolve_audit_events WHERE event_id = :id', ['id' => 'event-1'])));
        self::assertStringNotContainsString('secret-sentinel', $raw[0]['payload_json']);
        self::assertSame([], $log->read(new AuditReadRequest('auditor', 'tenant-C', 10)));
    }

    public function testInvalidIdentifiersPayloadAndRedactionConfigurationAreRejected(): void
    {
        $rejected = [];
        foreach (['', str_repeat('x', 129), 'bad id', '../unsafe'] as $id) {
            try {
                $this->event($id, 'actor-A', 'tenant-A');
                self::fail('Expected invalid event identifier.');
            } catch (\InvalidArgumentException $failure) { $rejected[] = $failure->getMessage(); }
        }
        foreach ([['x' => new \stdClass()], ['x' => INF], ['x' => str_repeat('a', 5000)]] as $payload) {
            try {
                $this->event('event-1', 'actor-A', 'tenant-A', $payload);
                self::fail('Expected invalid payload.');
            } catch (\InvalidArgumentException $failure) { $rejected[] = $failure->getMessage(); }
        }
        foreach ([['password'], ['access_token'], ['outcome', 'outcome']] as $fields) {
            try {
                new RedactionPolicy($fields);
                self::fail('Expected invalid redaction configuration.');
            } catch (\InvalidArgumentException $failure) { $rejected[] = $failure->getMessage(); }
        }
        self::assertCount(10, array_filter($rejected, static fn(string $message): bool => $message !== ''));
    }

    public function testUnauthorizedReadAndPurgeFailBeforeDatabaseAccess(): void
    {
        $connection = $this->connection();
        $log = $this->log($connection);
        $log->record($this->event('event-1', 'actor-A', 'tenant-A'));
        $this->expectException(UnauthorizedAuditAccess::class);
        $log->read(new AuditReadRequest('outsider', 'tenant-A', 10));
    }

    public function testDuplicateEventIdFailsVisibly(): void
    {
        $log = $this->log($this->connection());
        $log->record($this->event('event-1', 'actor-A', 'tenant-A'));
        $this->expectException(DuplicateAuditEvent::class);
        $log->record($this->event('event-1', 'actor-B', 'tenant-B'));
    }

    public function testRetentionIsAuthorizedExplicitAndTenantBounded(): void
    {
        $log = $this->log($this->connection());
        $log->record($this->event('event-1', 'actor-A', 'tenant-A'));
        $log->record($this->event('event-2', 'actor-B', 'tenant-B'));
        try {
            $log->purge(new AuditRetentionRequest('outsider', 'tenant-A', new DateTimeImmutable('2026-11-01T00:00:00Z')));
            self::fail('Expected unauthorized retention.');
        } catch (UnauthorizedAuditAccess) {}
        self::assertSame(1, $log->purge(new AuditRetentionRequest('retention-admin', 'tenant-A', new DateTimeImmutable('2026-11-01T00:00:00Z'))));
        self::assertCount(0, $log->read(new AuditReadRequest('auditor', 'tenant-A', 10)));
        self::assertCount(1, $log->read(new AuditReadRequest('auditor', 'tenant-B', 10)));
    }

    public function testSequentialExecutionAttributionDoesNotLeak(): void
    {
        $log = $this->log($this->connection());
        $log->record($this->event('event-1', 'actor-A', 'tenant-A'));
        $log->record($this->event('event-2', 'actor-B', 'tenant-B'));
        self::assertSame('actor-A', $log->read(new AuditReadRequest('auditor', 'tenant-A', 10))[0]->actorId);
        self::assertSame('actor-B', $log->read(new AuditReadRequest('auditor', 'tenant-B', 10))[0]->actorId);
    }

    public function testDatabaseAndTransactionFailuresRemainVisible(): void
    {
        $failure = new RuntimeException('database unavailable');
        $connection = new class($failure) implements DatabaseConnection {
            public function __construct(private RuntimeException $failure) {}
            public function execute(DatabaseStatement $statement): int { throw $this->failure; }
            public function query(DatabaseStatement $statement): iterable { throw $this->failure; }
            public function transaction(callable $operation): mixed { throw $this->failure; }
        };
        try {
            $this->log($connection)->record($this->event('event-1', 'actor-A', 'tenant-A'));
            self::fail('Expected write failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testQueryBoundsAndTenantIdentityAreValidated(): void
    {
        foreach ([0, 101] as $limit) {
            try {
                new AuditReadRequest('auditor', 'tenant-A', $limit);
                self::fail('Expected query limit rejection.');
            } catch (\InvalidArgumentException) {}
        }
        try {
            new AuditReadRequest('auditor', 'bad tenant', 10);
            self::fail('Expected tenant rejection.');
        } catch (\InvalidArgumentException $failure) {
            self::assertStringContainsString('Invalid audit', $failure->getMessage());
        }
        try {
            $this->event('event-1', '', 'tenant-A');
            self::fail('Expected actor rejection.');
        } catch (\InvalidArgumentException $failure) {
            self::assertStringContainsString('Invalid audit', $failure->getMessage());
        }
    }

    public function testMigrationIsModuleOwnedAndRequiresExplicitDatabase(): void
    {
        $contributor = new AuditLogMigrationContributor();
        $definition = iterator_to_array($contributor->definitions())[0];
        self::assertSame('module:evolvephp/audit-log', $definition->owner()->key());
        self::assertSame($definition->owner()->key(), $contributor->owner()->key());
        self::assertCount(1, MigrationRegistry::fromContributors([$contributor])->definitions());
        try {
            $definition->action()->invoke(null);
            self::fail('Expected missing database failure.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('requires a database connection', $failure->getMessage());
        }
    }

    public function testModuleRegistrationFailurePropagatesAndDisabledModuleIsInert(): void
    {
        $registrar = new class implements ServiceDefinitionRegistrar {
            private int $calls = 0;
            public function calls(): int { return $this->calls; }
            public function registerApplication(string $id, callable $factory): void
            {
                ++$this->calls;
                throw new RuntimeException('registration failed');
            }
            public function registerExecution(string $id, callable $factory): void { ++$this->calls; }
            public function registerTransient(string $id, callable $factory): void { ++$this->calls; }
        };
        self::assertSame(0, $registrar->calls()); // An application that omits the module invokes no lifecycle method.
        try {
            (new AuditLogModule())->register($registrar);
            self::fail('Expected registration failure.');
        } catch (RuntimeException $failure) {
            self::assertSame('registration failed', $failure->getMessage());
        }
        self::assertSame(1, $registrar->calls());
    }

    public function testUnauthorizedReadDoesNotReachStore(): void
    {
        $reads = 0;
        $store = new class($reads) implements \Evolve\AuditLog\AuditStore {
            public function __construct(private int &$reads) {}
            public function append(AuditEvent $event, array $safePayload): void {}
            public function read(AuditReadRequest $request): array { ++$this->reads; return []; }
            public function purge(AuditRetentionRequest $request): int { return 0; }
        };
        $log = new AuditLog($store, new RedactionPolicy([]), $this->policy());
        try {
            $log->read(new AuditReadRequest('outsider', 'tenant-A'));
            self::fail('Expected an unauthorized read failure.');
        } catch (UnauthorizedAuditAccess $failure) {
            self::assertStringContainsString('not authorized', $failure->getMessage());
        }
        self::assertSame(0, $reads);
    }

    public function testStorageRejectsCrossTenantRows(): void
    {
        $connection = new class implements DatabaseConnection {
            public function execute(DatabaseStatement $statement): int { return 0; }
            public function query(DatabaseStatement $statement): iterable { yield ['tenant_id' => 'tenant-B']; }
            public function transaction(callable $operation): mixed { return $operation($this); }
        };
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the requested tenant');
        $this->log($connection)->read(new AuditReadRequest('auditor', 'tenant-A'));
    }
    public function testModuleRegistersServicesWithoutRunningMigration(): void
    {
        $registrar = new class implements ServiceDefinitionRegistrar {
            /** @var array<string, list<string>> */
            private array $registered = [];
            /** @return array<string, list<string>> */
            public function registered(): array { return $this->registered; }
            public function registerApplication(string $id, callable $factory): void { $this->registered['application'][] = $id; }
            public function registerExecution(string $id, callable $factory): void { $this->registered['execution'][] = $id; }
            public function registerTransient(string $id, callable $factory): void { $this->registered['transient'][] = $id; }
        };
        (new AuditLogModule())->register($registrar);
        $registered = $registrar->registered();
        self::assertContains(AuditLog::class, $registered['execution']);
        self::assertContains(AuditLogMigrationContributor::class, $registered['application']);
    }
}