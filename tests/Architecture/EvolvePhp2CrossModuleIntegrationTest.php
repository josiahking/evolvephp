<?php

declare(strict_types=1);

namespace Evolve\Tests\Architecture;

use DateTimeImmutable;
use Evolve\ApiKeys\{ApiKeyAccessPolicy, ApiKeyIssueRequest, ApiKeyVerificationRequest, ApiKeys, ApiKeysMigrationContributor, ApiKeysModule, SqlApiKeyStore};
use Evolve\ApiKeys\ApiKeyDiagnosticLabel;
use Evolve\ApiKeys\Exception\UnauthorizedApiKeyOperation;
use Evolve\AuditLog\{AuditAccessPolicy, AuditEvent, AuditLog, AuditLogMigrationContributor, AuditLogModule, AuditReadRequest, RedactionPolicy, SqlAuditStore};
use Evolve\AuditLog\AuditEventLabel;
use Evolve\AuditLog\Exception\UnauthorizedAuditAccess;
use Evolve\Contracts\Component\{CapabilityIdentifier, CapabilityRequirement, ComponentGraphRelations, ComponentIdentifier};
use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\{ApplicationKernel, Component\ComponentBootstrapper, Configuration\ArrayConfiguration};
use Evolve\Core\Container\{ServiceLifetime, ServiceRegistry};
use Evolve\Core\Exception\{AmbiguousCapabilityProvider, ComponentServiceRegistrationFailed, DuplicateComponentIdentifier, ExecutionScopeClosed, ExecutionStartFailed};
use Evolve\Core\Execution\{ExecutionContextValues, ExecutionKind, ExecutionOrchestrator, ExecutionScope};
use Evolve\Database\Contracts\{DatabaseConnection, DatabaseStatement};
use Evolve\Database\Pdo\PdoDatabaseConnection;
use Evolve\I18n\{ArrayMessageCatalog, CatalogTranslator, FilesystemMessageCatalog, LocalizationContext, LocalizationPolicy, MessageCatalog, MessageName, MessageSource, PlaceholderMessageFormatter, Translator};
use Evolve\I18n\Exception\MessageNotFound;
use Evolve\Module\{Module, ModuleDefinition, ModuleDescriptor};
use Evolve\Webhooks\{KeyProvider, SqlWebhookStore, TrustedTransport, WebhookAccessPolicy, WebhookEvent, WebhookSubscription, Webhooks, WebhooksMigrationContributor, WebhooksModule};
use Evolve\Webhooks\Signature;
use Evolve\Webhooks\WebhookDiagnosticLabel;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EvolvePhp2CrossModuleIntegrationTest extends TestCase
{
    private const IDS = ['evolvephp/audit-log', 'evolvephp/webhooks', 'evolvephp/api-keys'];

    public function testExplicitModuleBootIsInertAndOmittedModulesRemainInactive(): void
    {
        [$kernel, $registry, $db, $transport] = $this->application(self::IDS);
        self::assertSame([], $db->operations());
        self::assertSame([], $this->tables($db));
        $db->clearOperations();
        $kernel->boot();
        self::assertSame([], $db->operations());
        $services = $registry->freeze();
        self::assertTrue($services->has(AuditLog::class));
        self::assertTrue($services->has(Webhooks::class));
        self::assertTrue($services->has(ApiKeys::class));
        self::assertSame([], $this->tables($db));
        self::assertSame([], $transport->calls);
        $kernel->shutdown();

        [$single, $singleRegistry] = $this->application(['evolvephp/audit-log']);
        $single->boot();
        $singleServices = $singleRegistry->freeze();
        self::assertTrue($singleServices->has(AuditLog::class));
        self::assertFalse($singleServices->has(Webhooks::class));
        self::assertFalse($singleServices->has(ApiKeys::class));
        $single->shutdown();
    }

    public function testGraphAndServiceConflictsDoNotPartiallyRegisterModules(): void
    {
        $definitions = $this->definitions();
        try {
            new ComponentBootstrapper([$definitions[0], $definitions[0]]);
            self::fail('Duplicate module identifier must be rejected.');
        } catch (DuplicateComponentIdentifier $failure) {
            self::assertSame('evolvephp/audit-log', $failure->identifier()->value());
        }

        $capability = new CapabilityIdentifier('host.integration');
        $provider = new ComponentGraphRelations(providedCapabilities: [$capability]);
        $consumer = new ComponentGraphRelations(requiredCapabilities: [new CapabilityRequirement($capability)]);
        $host = [
            ...$definitions,
            $this->hostDefinition('host/provider-a', $provider),
            $this->hostDefinition('host/provider-b', $provider),
            $this->hostDefinition('host/consumer', $consumer),
        ];
        $registry = new ServiceRegistry();
        $kernel = new ApplicationKernel($this->enabled([...self::IDS, 'host/provider-a', 'host/provider-b', 'host/consumer']), [], $registry, new ComponentBootstrapper($host));
        try {
            $kernel->boot();
            self::fail('Ambiguous capability must fail before registration.');
        } catch (AmbiguousCapabilityProvider $failure) {
            self::assertSame('host.integration', $failure->capability()->value());
            self::assertSame([], $registry->definitionIdentifiers());
        }

        [$kernel, $registry] = $this->application(self::IDS);
        $registry->register(SqlWebhookStore::class, ServiceLifetime::Application, static fn(): object => new \stdClass());
        $before = $registry->definitionIdentifiers();
        try {
            $kernel->boot();
            self::fail('Duplicate service must prevent publication.');
        } catch (ComponentServiceRegistrationFailed $failure) {
            self::assertSame('evolvephp/webhooks', $failure->component()->value());
            self::assertSame($before, $registry->definitionIdentifiers());
        }
    }

    public function testMigrationsExecutionStorageAndCredentialsRemainIsolated(): void
    {
        [$kernel, $registry, $db, $transport] = $this->application(self::IDS);
        $kernel->boot();
        $this->migrate($db);
        self::assertSame([
            'evolve_api_keys', 'evolve_audit_events', 'evolve_webhook_deliveries',
            'evolve_webhook_events', 'evolve_webhook_subscriptions',
        ], $this->tables($db));
        $orchestrator = new ExecutionOrchestrator($registry);
        $first = $orchestrator->execute(ExecutionKind::WorkerTask, static function ($context, ExecutionScope $scope): array {
            $audit = $scope->get(AuditLog::class);
            $keys = $scope->get(ApiKeys::class);
            $webhooks = $scope->get(Webhooks::class);
            $issued = $keys->issue(new ApiKeyIssueRequest('alice', 'alice', 'tenant-a', ['read'], new DateTimeImmutable('+1 day')));
            $audit->record(new AuditEvent('audit-a', 'credential.issued', new DateTimeImmutable('2026-01-01T00:00:00Z'), 'alice', 'tenant-a', payload: ['action' => 'issued', 'credential' => $issued->credential()]));
            $webhooks->subscribe('alice', new WebhookSubscription('sub-a', 'created', 'tenant-a', 'https://example.com/hook', 'signing-a'));
            $deliveries = $webhooks->publish('alice', new WebhookEvent('event-a', 'created', 'tenant-a', ['kind' => 'created']));
            self::assertCount(1, $deliveries);
            return [$scope, $audit, $keys, $webhooks, $issued, $deliveries[0], $scope->get(SqlAuditStore::class), $scope->get(SqlWebhookStore::class), $scope->get(SqlApiKeyStore::class)];
        }, new ExecutionContextValues('en', 'UTC'));
        self::assertTrue($first->primarySucceeded());
        self::assertTrue($first->isReusable());
        [$scope1, $audit1, $keys1, $webhooks1, $issued, $deliveryId, $auditStore, $webhookStore, $keyStore] = $first->primaryResult();
        $this->assertClosed($scope1);
        self::assertSame([], $transport->calls);

        $second = $orchestrator->execute(ExecutionKind::WorkerTask, static function ($context, ExecutionScope $scope) use ($audit1, $keys1, $webhooks1, $issued, $deliveryId, $auditStore, $webhookStore, $keyStore): array {
            $audit = $scope->get(AuditLog::class);
            $keys = $scope->get(ApiKeys::class);
            $webhooks = $scope->get(Webhooks::class);
            self::assertNotSame($audit1, $audit);
            self::assertNotSame($keys1, $keys);
            self::assertNotSame($webhooks1, $webhooks);
            self::assertSame($auditStore, $scope->get(SqlAuditStore::class));
            self::assertSame($webhookStore, $scope->get(SqlWebhookStore::class));
            self::assertSame($keyStore, $scope->get(SqlApiKeyStore::class));
            self::assertCount(1, $audit->read(new AuditReadRequest('alice', 'tenant-a')));
            self::assertSame([], $scope->get(SqlAuditStore::class)->read(new AuditReadRequest('alice', 'tenant-b')));
            self::assertTrue($keys->verify(new ApiKeyVerificationRequest($issued->credential(), 'alice', 'tenant-a', ['read'], new DateTimeImmutable('now')))->allowed());
            self::assertFalse($keys->verify(new ApiKeyVerificationRequest($issued->credential(), 'alice', 'tenant-b', ['read'], new DateTimeImmutable('now')))->allowed());
            self::assertSame('acknowledged', $webhooks->deliver('tenant-a', $deliveryId, 'lease-a', time()));
            return [$scope, $context->locale()];
        }, new ExecutionContextValues('fr', 'UTC'));
        self::assertTrue($second->primarySucceeded(), $second->primaryThrowable()?->getMessage() ?? 'Execution failed.');
        [$scope2, $locale] = $second->primaryResult();
        self::assertSame('fr', $locale);
        $this->assertClosed($scope2);
        self::assertSame(1, $transport->callCount());
        self::assertStringNotContainsString($issued->credential(), json_encode($transport->calls, JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->countRows($db, 'evolve_audit_events'));
        self::assertSame(1, $this->countRows($db, 'evolve_api_keys'));
        self::assertSame(1, $this->countRows($db, 'evolve_webhook_events'));
        foreach ($db->query(new DatabaseStatement('SELECT event_type, payload_json FROM evolve_audit_events', [], 'integration.audit-evidence')) as $row) {
            self::assertSame('credential.issued', $row['event_type']);
            self::assertSame('{"action":"issued"}', $row['payload_json']);
            self::assertStringNotContainsString($issued->credential(), (string) $row['payload_json']);
        }
        foreach ($db->query(new DatabaseStatement('SELECT state FROM evolve_webhook_deliveries', [], 'integration.delivery-evidence')) as $row) {
            self::assertSame('acknowledged', $row['state']);
        }
        $call = $transport->firstCall();
        $headers = $call['headers'];
        self::assertSame('signing-a', $headers['X-Evolve-Webhook-Key']);
        $body = $call['body'];
        $timestamp = (int) $headers['X-Evolve-Webhook-Timestamp'];
        self::assertTrue(Signature::verify($body, $headers, 'synthetic-webhook-signing-secret', $timestamp, 300));
        self::assertFalse(Signature::verify($body, $headers, $issued->credential(), $timestamp, 300));
        $kernel->shutdown();
    }

    public function testDeniedCrossModuleOperationsDoNotWriteAndFailedExecutionCleansUp(): void
    {
        [$kernel, $registry, $db, $transport] = $this->application(self::IDS, false);
        $kernel->boot();
        $this->migrate($db);
        $orchestrator = new ExecutionOrchestrator($registry);
        $captured = null;
        $outcome = $orchestrator->execute(ExecutionKind::WorkerTask, static function ($context, ExecutionScope $scope) use (&$captured): void {
            $captured = $scope;
            $audit = $scope->get(AuditLog::class);
            $audit->record(new AuditEvent('audit-denied', 'access.denied', new DateTimeImmutable('2026-01-01T00:00:00Z'), 'alice', 'tenant-a'));
            self::assertCount(1, $audit->read(new AuditReadRequest('alice', 'tenant-a')));
            try {
                $scope->get(Webhooks::class)->publish('alice', new WebhookEvent('mixed-denied', 'created', 'tenant-a', []));
                self::fail('Webhook policy must deny publication even when audit read is permitted.');
            } catch (\InvalidArgumentException $failure) {
                self::assertSame('Webhook publication is not authorized.', $failure->getMessage());
            }
            try {
                $audit->read(new AuditReadRequest('bob', 'tenant-a'));
                self::fail('Audit read must be denied.');
            } catch (UnauthorizedAuditAccess) {
            }
            try {
                $scope->get(ApiKeys::class)->issue(new ApiKeyIssueRequest('bob', 'bob', 'tenant-a', ['read'], new DateTimeImmutable('+1 day')));
                self::fail('Credential issue must be denied.');
            } catch (UnauthorizedApiKeyOperation) {
            }
            try {
                $scope->get(Webhooks::class)->publish('bob', new WebhookEvent('denied-event', 'created', 'tenant-a', []));
                self::fail('Webhook publication must be denied.');
            } catch (\InvalidArgumentException $failure) {
                self::assertSame('Webhook publication is not authorized.', $failure->getMessage());
            }
            try {
                $audit->read(new AuditReadRequest('alice', 'tenant-b'));
                self::fail('Another tenant must not inherit audit access.');
            } catch (UnauthorizedAuditAccess) {
            }
            try {
                $scope->get(ApiKeys::class)->issue(new ApiKeyIssueRequest('alice', 'alice', 'tenant-b', ['read'], new DateTimeImmutable('+1 day')));
                self::fail('Another tenant must not inherit key issuance.');
            } catch (UnauthorizedApiKeyOperation) {
            }
            try {
                $scope->get(Webhooks::class)->subscribe('alice', new WebhookSubscription('sub-denied', 'created', 'tenant-b', 'https://example.com/hook', 'signing-a'));
                self::fail('Another tenant must not inherit webhook subscription.');
            } catch (\InvalidArgumentException $failure) {
                self::assertSame('Webhook subscription is not authorized.', $failure->getMessage());
            }
            throw new RuntimeException('synthetic handler failure');
        });
        self::assertTrue($outcome->primaryFailed());
        self::assertSame('synthetic handler failure', $outcome->primaryThrowableOrFail()->getMessage());
        self::assertTrue($outcome->isReusable());
        self::assertNotNull($captured);
        $this->assertClosed($captured);
        self::assertSame(1, $this->countRows($db, 'evolve_audit_events'));
        self::assertSame(0, $this->countRows($db, 'evolve_api_keys'));
        self::assertSame(0, $this->countRows($db, 'evolve_webhook_events'));
        self::assertSame(0, $this->countRows($db, 'evolve_webhook_subscriptions'));
        self::assertSame(0, $this->countRows($db, 'evolve_webhook_deliveries'));
        self::assertSame([], $transport->calls);
        $recovered = $orchestrator->execute(ExecutionKind::WorkerTask, static function ($context, ExecutionScope $scope): string {
            try {
                $scope->get(AuditLog::class)->read(new AuditReadRequest('bob', 'tenant-b'));
                self::fail('A later execution inherited an earlier audit grant.');
            } catch (UnauthorizedAuditAccess) {
                return 'tenant-b-denied';
            }
        });
        self::assertTrue($recovered->primarySucceeded());
        self::assertSame('tenant-b-denied', $recovered->primaryResult());
        $kernel->shutdown();
    }

    public function testCleanupFailureQuarantinesTheOrchestrator(): void
    {
        [$kernel, $registry] = $this->application(self::IDS);
        $kernel->boot();
        $orchestrator = new ExecutionOrchestrator($registry);
        $outcome = $orchestrator->execute(ExecutionKind::WorkerTask, static function ($context, ExecutionScope $scope): void {
            $scope->get(AuditLog::class);
            $scope->registerResetParticipant('synthetic', new class implements ResetParticipant {
                public function reset(): void
                {
                    throw new RuntimeException('synthetic cleanup failure');
                }
            });
        });
        self::assertTrue($outcome->cleanupFailed());
        self::assertTrue($outcome->requiresQuarantine());
        try {
            $orchestrator->execute(ExecutionKind::WorkerTask, static fn(): null => null);
            self::fail('Quarantined orchestrator accepted another execution.');
        } catch (ExecutionStartFailed $failure) {
            self::assertSame('Execution orchestrator is quarantined and cannot accept more work.', $failure->getMessage());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testCatalogNamespacesFallbackAndStableDiagnostics(): void
    {
        $root = dirname(__DIR__, 2);
        $policy = new LocalizationPolicy(['en', 'fr'], 'en', ['en'], 'UTC');
        $host = new ArrayMessageCatalog([
            ['namespace' => 'audit-log', 'locale' => 'en', 'messages' => ['credential.issued' => 'Credential issued']],
            ['namespace' => 'audit-log', 'locale' => 'fr', 'messages' => ['credential.issued' => 'Identifiant cree']],
        ]);
        $packages = new FilesystemMessageCatalog([
            new MessageSource('webhooks', $root . '/packages/webhooks/resources/lang'),
            new MessageSource('api-keys', $root . '/packages/api-keys/resources/lang'),
        ], $policy);
        $translator = new RecordingTranslator(new CatalogTranslator(new CombinedCatalog($host, $packages), new PlaceholderMessageFormatter()));
        $event = new AuditEvent('label-a', 'credential.issued', new DateTimeImmutable('2026-01-01T00:00:00Z'), 'alice', 'tenant-a');
        $audit = new AuditEventLabel($translator);
        $webhooks = new WebhookDiagnosticLabel($translator);
        $keys = new ApiKeyDiagnosticLabel($translator);
        self::assertSame('Credential issued', $audit->translate($event, $policy->context('en')));
        self::assertSame('Identifiant cree', $audit->translate($event, $policy->context('fr')));
        self::assertSame('Webhook delivery was acknowledged.', $webhooks->translate('delivery.acknowledged', $policy->context('fr')));
        self::assertSame('API key access was denied.', $keys->denied($policy->context('fr')));
        self::assertSame('credential.issued', $event->type);
        [$kernel, $registry, $db, $transport] = $this->application(self::IDS);
        $kernel->boot();
        $this->migrate($db);
        $orchestrator = new ExecutionOrchestrator($registry);
        $issuedOutcome = $orchestrator->execute(ExecutionKind::WorkerTask, static function ($context, ExecutionScope $scope) use ($event): \Evolve\ApiKeys\IssuedApiKey {
            $issued = $scope->get(ApiKeys::class)->issue(new ApiKeyIssueRequest('alice', 'alice', 'tenant-a', ['read'], new DateTimeImmutable('+1 day')));
            $scope->get(AuditLog::class)->record($event);
            $scope->get(Webhooks::class)->subscribe('alice', new WebhookSubscription('localized-sub', 'created', 'tenant-a', 'https://example.com/hook', 'signing-a'));
            $scope->get(Webhooks::class)->publish('alice', new WebhookEvent('localized-event', 'created', 'tenant-a', ['kind' => 'created']));
            return $issued;
        });
        self::assertTrue($issuedOutcome->primarySucceeded());
        $issued = $issuedOutcome->primaryResult();
        $render = static function ($context, ExecutionScope $scope) use ($audit, $event, $keys, $webhooks, $policy, $issued): array {
            $denied = $scope->get(ApiKeys::class)->verify(new ApiKeyVerificationRequest(
                $issued->credential(),
                'alice',
                'tenant-b',
                ['read'],
                new DateTimeImmutable('now'),
            ));
            return [
                $context->locale(),
                $audit->translate($event, $policy->context($context->locale())),
                $keys->denied($policy->context($context->locale())),
                $webhooks->translate('delivery.queued', $policy->context($context->locale())),
                json_encode($denied, JSON_THROW_ON_ERROR),
            ];
        };
        $first = $orchestrator->execute(ExecutionKind::WorkerTask, $render, new ExecutionContextValues('fr', 'UTC'));
        $second = $orchestrator->execute(ExecutionKind::WorkerTask, $render, new ExecutionContextValues('en', 'UTC'));
        self::assertTrue($first->primarySucceeded());
        self::assertTrue($second->primarySucceeded());
        self::assertSame(['fr', 'Identifiant cree', 'API key access was denied.', 'Webhook delivery is queued.', '{"allowed":false}'], $first->primaryResult());
        self::assertSame(['en', 'Credential issued', 'API key access was denied.', 'Webhook delivery is queued.', '{"allowed":false}'], $second->primaryResult());
        self::assertSame([
            'audit-log::credential.issued',
            'audit-log::credential.issued',
            'webhooks::delivery.acknowledged',
            'api-keys::denied',
            'audit-log::credential.issued',
            'api-keys::denied',
            'webhooks::delivery.queued',
            'audit-log::credential.issued',
            'api-keys::denied',
            'webhooks::delivery.queued',
        ], array_column($translator->calls, 'message'));
        foreach ($translator->calls as $call) {
            self::assertSame([], $call['parameters']);
        }
        self::assertStringNotContainsString($issued->credential(), json_encode(
            [$first->primaryResult(), $second->primaryResult(), $translator->calls],
            JSON_THROW_ON_ERROR,
        ));
        foreach ($db->query(new DatabaseStatement('SELECT event_type FROM evolve_audit_events', [], 'integration.localized-audit')) as $row) {
            self::assertSame('credential.issued', $row['event_type']);
        }
        foreach ($db->query(new DatabaseStatement('SELECT event_type FROM evolve_webhook_events', [], 'integration.localized-webhook')) as $row) {
            self::assertSame('created', $row['event_type']);
        }
        foreach ($db->query(new DatabaseStatement('SELECT state FROM evolve_webhook_deliveries', [], 'integration.localized-delivery')) as $row) {
            self::assertSame('queued', $row['state']);
        }
        self::assertSame([], $transport->calls);
        $kernel->shutdown();
        $this->expectException(MessageNotFound::class);
        $webhooks->translate('credential.issued', $policy->context('en'));
    }

    /**
     * @param list<string> $enabled
     * @return array{ApplicationKernel, ServiceRegistry, RecordingDatabaseConnection, RecordingTransport}
     */
    private function application(array $enabled, bool $webhookPublicationAllowed = true): array
    {
        $db = new RecordingDatabaseConnection(new PdoDatabaseConnection(new PDO('sqlite::memory:')));
        $transport = new RecordingTransport();
        $registry = new ServiceRegistry();
        $registry->register(DatabaseConnection::class, ServiceLifetime::Application, static fn(): DatabaseConnection => $db);
        $registry->register(RedactionPolicy::class, ServiceLifetime::Application, static fn(): RedactionPolicy => new RedactionPolicy(['action']));
        $registry->register(AuditAccessPolicy::class, ServiceLifetime::Application, static fn(): AuditAccessPolicy => new TestModulePolicy());
        $registry->register(ApiKeyAccessPolicy::class, ServiceLifetime::Application, static fn(): ApiKeyAccessPolicy => new TestModulePolicy());
        $registry->register(WebhookAccessPolicy::class, ServiceLifetime::Application, static fn(): WebhookAccessPolicy => new TestModulePolicy(webhookPublicationAllowed: $webhookPublicationAllowed));
        $registry->register(KeyProvider::class, ServiceLifetime::Application, static fn(): KeyProvider => new TestKeyProvider());
        $registry->register(TrustedTransport::class, ServiceLifetime::Application, static fn(): TrustedTransport => $transport);
        return [new ApplicationKernel($this->enabled($enabled), [], $registry, new ComponentBootstrapper($this->definitions())), $registry, $db, $transport];
    }

    /** @param list<string> $ids */
    private function enabled(array $ids): ArrayConfiguration
    {
        return new ArrayConfiguration(['evolve' => ['components' => ['enabled' => $ids]]]);
    }

    /** @return list<ModuleDefinition> */
    private function definitions(): array
    {
        return [
            new ModuleDefinition(new ModuleDescriptor(new ComponentIdentifier(self::IDS[0]), 'Audit Log', 2), AuditLogModule::class),
            new ModuleDefinition(new ModuleDescriptor(new ComponentIdentifier(self::IDS[1]), 'Webhooks', 2), WebhooksModule::class),
            new ModuleDefinition(new ModuleDescriptor(new ComponentIdentifier(self::IDS[2]), 'API Keys', 2), ApiKeysModule::class),
        ];
    }

    private function hostDefinition(string $id, ComponentGraphRelations $relations): ModuleDefinition
    {
        return new ModuleDefinition(new ModuleDescriptor(new ComponentIdentifier($id), $id, 2, $relations), TestHostModule::class);
    }

    private function migrate(DatabaseConnection $db): void
    {
        $identities = [];
        foreach ([new AuditLogMigrationContributor(), new WebhooksMigrationContributor(), new ApiKeysMigrationContributor()] as $contributor) {
            foreach ($contributor->definitions() as $definition) {
                $identities[] = [$definition->owner()->componentIdentifier()?->value(), $definition->identifier()->value()];
                $definition->action()->invoke($db);
            }
        }
        self::assertSame([
            ['evolvephp/audit-log', 'create_audit_events'],
            ['evolvephp/webhooks', 'create_webhook_tables'],
            ['evolvephp/api-keys', 'create_api_keys'],
        ], $identities);
    }

    /** @return list<string> */
    private function tables(DatabaseConnection $db): array
    {
        $names = [];
        foreach ($db->query(new DatabaseStatement("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name", [], 'integration.tables')) as $row) {
            $names[] = (string) $row['name'];
        }
        return $names;
    }

    private function countRows(DatabaseConnection $db, string $table): int
    {
        foreach ($db->query(new DatabaseStatement('SELECT COUNT(*) AS total FROM ' . $table, [], 'integration.count')) as $row) {
            return (int) $row['total'];
        }
        throw new RuntimeException('Count query returned no row.');
    }

    private function assertClosed(ExecutionScope $scope): void
    {
        try {
            $scope->get(AuditLog::class);
            self::fail('Closed scope remained accessible.');
        } catch (ExecutionScopeClosed) {

        }
    }
}

final class TestModulePolicy implements AuditAccessPolicy, ApiKeyAccessPolicy, WebhookAccessPolicy
{
    public function __construct(private bool $webhookPublicationAllowed = true) {}

    public function canRead(string $principal, ?string $tenant): bool
    {
        return $principal === 'alice' && $tenant === 'tenant-a';
    }
    public function canPurge(string $principal, ?string $tenant): bool
    {
        return false;
    }
    public function canIssue(string $actor, string $principal, string $tenant, array $scopes): bool
    {
        return $actor === 'alice' && $principal === 'alice' && $tenant === 'tenant-a' && $scopes === ['read'];
    }
    public function canRevoke(string $actor, string $principal, string $tenant): bool
    {
        return false;
    }
    public function canSubscribe(string $principal, string $tenantId): bool
    {
        return $principal === 'alice' && $tenantId === 'tenant-a';
    }
    public function canPublish(string $principal, string $tenantId, string $eventType): bool
    {
        return $this->webhookPublicationAllowed && $principal === 'alice' && $tenantId === 'tenant-a' && $eventType === 'created';
    }
}

final class TestKeyProvider implements KeyProvider
{
    public function resolve(string $keyId, int $at): ?string
    {
        return $keyId === 'signing-a' ? 'synthetic-webhook-signing-secret' : null;
    }
}

final class RecordingTransport implements TrustedTransport
{
    /** @var list<array{url: string, body: string, headers: array<string, string>}> */
    public array $calls = [];

    public function callCount(): int
    {
        return count($this->calls);
    }

    /** @return array{url: string, body: string, headers: array<string, string>} */
    public function firstCall(): array
    {
        return $this->calls[0] ?? throw new RuntimeException('No transport call was recorded.');
    }

    public function send(string $url, string $body, array $headers): int
    {
        $this->calls[] = ['url' => $url, 'body' => $body, 'headers' => $headers];
        return 200;
    }
}

final class CombinedCatalog implements MessageCatalog
{
    public function __construct(private MessageCatalog $host, private MessageCatalog $packages) {}
    public function get(MessageName $name, string $locale): ?string
    {
        return $this->host->get($name, $locale) ?? $this->packages->get($name, $locale);
    }
}

final class TestHostModule implements Module
{
    public function register(\Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar $registrar): void {}
    public function boot(\Evolve\Contracts\Component\ComponentBootContext $context): void {}
    public function ready(): void {}
    public function shutdown(): void {}
}


final class RecordingDatabaseConnection implements DatabaseConnection
{
    /** @var list<string> */
    private array $operations = [];

    public function __construct(private DatabaseConnection $inner) {}

    public function execute(DatabaseStatement $statement): int
    {
        $this->operations[] = 'execute';
        return $this->inner->execute($statement);
    }

    public function query(DatabaseStatement $statement): iterable
    {
        $this->operations[] = 'query';
        return $this->inner->query($statement);
    }

    public function transaction(callable $operation): mixed
    {
        $this->operations[] = 'transaction';
        return $this->inner->transaction(function (DatabaseConnection $connection) use ($operation): mixed {
            return $operation($this);
        });
    }

    /** @return list<string> */
    public function operations(): array
    {
        return $this->operations;
    }

    public function clearOperations(): void
    {
        $this->operations = [];
    }
}


final class RecordingTranslator implements Translator
{
    /** @var list<array{message: string, parameters: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(private Translator $inner) {}

    public function translate(string $message, LocalizationContext $context, array $parameters = []): string
    {
        $this->calls[] = ['message' => $message, 'parameters' => $parameters];
        return $this->inner->translate($message, $context, $parameters);
    }
}
