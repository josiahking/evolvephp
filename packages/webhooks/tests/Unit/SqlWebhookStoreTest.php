<?php

declare(strict_types=1);

namespace Evolve\Webhooks\Tests\Unit;

require_once __DIR__ . '/../../src/WebhookEvent.php';
require_once __DIR__ . '/../../src/DestinationPolicy.php';
require_once __DIR__ . '/../../src/WebhookSubscription.php';
require_once __DIR__ . '/../../src/DeliveryWork.php';
require_once __DIR__ . '/../../src/SqlWebhookStore.php';
require_once __DIR__ . '/../../src/WebhooksMigrationContributor.php';

use Evolve\Database\Pdo\PdoDatabaseConnection;
use Evolve\Webhooks\SqlWebhookStore;
use Evolve\Webhooks\WebhookEvent;
use Evolve\Webhooks\WebhooksMigrationContributor;
use Evolve\Webhooks\WebhookSubscription;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqlWebhookStoreTest extends TestCase
{
    private function store(): SqlWebhookStore
    {
        $db = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
        foreach ((new WebhooksMigrationContributor())->definitions() as $definition) {
            $definition->action()->invoke($db);
        }
        return new SqlWebhookStore($db);
    }

    public function testPublishIsTenantScopedAndClaimsAtomically(): void
    {
        $store = $this->store();
        $store->addSubscription(new WebhookSubscription('sub-a', 'user.created', 'tenant-a', 'https://example.com/hook', 'key-1'));
        $store->addSubscription(new WebhookSubscription('sub-b', 'user.created', 'tenant-b', 'https://example.com/hook', 'key-1'));
        $ids = $store->publish(new WebhookEvent('event-1', 'user.created', 'tenant-a', ['id' => 1]));
        self::assertCount(1, $ids);
        self::assertNull($store->claim('tenant-b', $ids[0], 'lease-b', 1000, 3));
        $work = $store->claim('tenant-a', $ids[0], 'lease-a', 1000, 3);
        self::assertNotNull($work);
        self::assertSame('event-1', $work->event->id);
        self::assertNull($store->claim('tenant-a', $ids[0], 'lease-other', 1000, 3));
        $store->complete($work, 'acknowledged', 204, 1000);
        self::assertNull($store->claim('tenant-a', $ids[0], 'lease-late', 2000, 3));
    }

    public function testExpiredClaimCannotCompleteAReclaimedAttempt(): void
    {
        $store = $this->store();
        $store->addSubscription(new WebhookSubscription('sub-a', 'user.created', 'tenant-a', 'https://example.com/hook', 'key-1'));
        $id = $store->publish(new WebhookEvent('event-1', 'user.created', 'tenant-a', []))[0];
        $old = $store->claim('tenant-a', $id, 'same-token', 1000, 3);
        self::assertNotNull($old);
        $new = $store->claim('tenant-a', $id, 'same-token', 1062, 3);
        self::assertNotNull($new);
        $this->expectException(\RuntimeException::class);
        $store->complete($old, 'acknowledged', 204, 1062);
    }
    public function testMaximumAttemptCountBecomesTerminal(): void
    {
        $store = $this->store();
        $store->addSubscription(new WebhookSubscription('sub-a', 'user.created', 'tenant-a', 'https://example.com/hook', 'key-1'));
        $id = $store->publish(new WebhookEvent('event-1', 'user.created', 'tenant-a', []))[0];
        $work = $store->claim('tenant-a', $id, 'lease-1', 1000, 1);
        self::assertNotNull($work);
        $store->complete($work, 'retry', 503, 1060);
        self::assertNull($store->claim('tenant-a', $id, 'lease-2', 1060, 1));
    }

    public function testExpiredFinalLeaseIsReconciledOnceWithoutAnotherAttempt(): void
    {
        $db = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
        foreach ((new WebhooksMigrationContributor())->definitions() as $definition) {
            $definition->action()->invoke($db);
        }
        $workerA = new SqlWebhookStore($db);
        $workerB = new SqlWebhookStore($db);
        $workerA->addSubscription(new WebhookSubscription('sub-a', 'user.created', 'tenant-a', 'https://example.com/hook', 'key-1'));
        $id = $workerA->publish(new WebhookEvent('event-1', 'user.created', 'tenant-a', []))[0];
        $crashed = $workerA->claim('tenant-a', $id, 'lease-a', 1000, 1);
        self::assertNotNull($crashed);
        self::assertNull($workerB->claim('tenant-a', $id, 'lease-b', 1060, 1));
        self::assertNull($workerB->claim('tenant-b', $id, 'lease-b', 1061, 1));
        self::assertNull($workerB->claim('tenant-a', $id, 'lease-b', 1061, 1));
        self::assertNull($workerA->claim('tenant-a', $id, 'lease-c', 1061, 1));
        self::assertNull($workerA->claim('tenant-a', $id, 'lease-d', 2000, 1));
        $rows = iterator_to_array($db->query(new \Evolve\Database\Contracts\DatabaseStatement(
            'SELECT state, attempts, lease_token, lease_until FROM evolve_webhook_deliveries WHERE delivery_id = :id AND tenant_id = :tenant',
            ['id' => $id, 'tenant' => 'tenant-a'],
        )));
        self::assertCount(1, $rows);
        self::assertSame('terminal', $rows[0]['state']);
        self::assertSame(1, (int) $rows[0]['attempts']);
        self::assertNull($rows[0]['lease_token']);
        self::assertNull($rows[0]['lease_until']);
        $this->expectException(\RuntimeException::class);
        $workerA->complete($crashed, 'acknowledged', 204, 1061);
    }

    public function testDueQueuedDeliveryIsTerminalWhenMaximumDecreases(): void
    {
        $db = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
        foreach ((new WebhooksMigrationContributor())->definitions() as $definition) {
            $definition->action()->invoke($db);
        }
        $workerA = new SqlWebhookStore($db);
        $workerB = new SqlWebhookStore($db);
        $workerA->addSubscription(new WebhookSubscription('sub-a', 'user.created', 'tenant-a', 'https://example.com/hook', 'key-1'));
        $id = $workerA->publish(new WebhookEvent('event-1', 'user.created', 'tenant-a', []))[0];
        $old = $workerA->claim('tenant-a', $id, 'lease-1', 1000, 3);
        self::assertNotNull($old);
        $workerA->complete($old, 'retry', 503, 1060);
        self::assertNull($workerB->claim('tenant-b', $id, 'wrong-tenant', 1060, 1));
        $rows = iterator_to_array($db->query(new \Evolve\Database\Contracts\DatabaseStatement(
            'SELECT state, attempts FROM evolve_webhook_deliveries WHERE delivery_id = :id',
            ['id' => $id],
        )));
        self::assertSame('queued', $rows[0]['state']);
        self::assertSame(1, (int) $rows[0]['attempts']);
        self::assertNull($workerB->claim('tenant-a', $id, 'lease-2', 1060, 1));
        self::assertNull($workerA->claim('tenant-a', $id, 'lease-3', 1060, 1));
        self::assertNull($workerA->claim('tenant-a', $id, 'lease-4', 2000, 1));
        $rows = iterator_to_array($db->query(new \Evolve\Database\Contracts\DatabaseStatement(
            'SELECT state, attempts, lease_token, lease_until FROM evolve_webhook_deliveries WHERE delivery_id = :id',
            ['id' => $id],
        )));
        self::assertCount(1, $rows);
        self::assertSame('terminal', $rows[0]['state']);
        self::assertSame(1, (int) $rows[0]['attempts']);
        self::assertNull($rows[0]['lease_token']);
        self::assertNull($rows[0]['lease_until']);
        $this->expectException(\RuntimeException::class);
        $workerA->complete($old, 'acknowledged', 204, 2000);
    }

    public function testReclaimedFinalAttemptCannotBeCompletedByEarlierWorker(): void
    {
        $store = $this->store();
        $store->addSubscription(new WebhookSubscription('sub-a', 'user.created', 'tenant-a', 'https://example.com/hook', 'key-1'));
        $id = $store->publish(new WebhookEvent('event-1', 'user.created', 'tenant-a', []))[0];
        $first = $store->claim('tenant-a', $id, 'same-token', 1000, 2);
        self::assertNotNull($first);
        $final = $store->claim('tenant-a', $id, 'same-token', 1061, 2);
        self::assertNotNull($final);
        self::assertSame(2, $final->attempts);
        try {
            $store->complete($first, 'acknowledged', 204, 1061);
            self::fail('Earlier worker completed a reclaimed attempt.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Webhook completion lost its claim.', $failure->getMessage());
        }
        self::assertNull($store->claim('tenant-a', $id, 'other-worker', 1122, 2));
        $this->expectException(\RuntimeException::class);
        $store->complete($final, 'acknowledged', 204, 1122);
    }

    public function testDeliveryInsertFailureRollsBackEventPublication(): void
    {
        $db = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
        foreach ((new WebhooksMigrationContributor())->definitions() as $definition) {
            $definition->action()->invoke($db);
        }
        $db->execute(new \Evolve\Database\Contracts\DatabaseStatement(
            "CREATE TRIGGER reject_delivery BEFORE INSERT ON evolve_webhook_deliveries BEGIN SELECT RAISE(ABORT, 'rejected'); END",
        ));
        $store = new SqlWebhookStore($db);
        $store->addSubscription(new WebhookSubscription('sub-a', 'user.created', 'tenant-a', 'https://example.com/hook', 'key-1'));
        try {
            $store->publish(new WebhookEvent('event-1', 'user.created', 'tenant-a', ['secret' => 'secret-sentinel']));
            self::fail('Expected delivery insert failure.');
        } catch (\Evolve\Database\Contracts\Exception\DatabaseException $failure) {
            self::assertStringNotContainsString('secret-sentinel', $failure->getMessage());
        }
        $rows = iterator_to_array($db->query(new \Evolve\Database\Contracts\DatabaseStatement(
            'SELECT event_id FROM evolve_webhook_events',
        )));
        self::assertSame([], $rows);
    }
    public function testDuplicateEventIdentifierIsRejected(): void
    {
        $store = $this->store();
        $event = new WebhookEvent('event-1', 'user.created', 'tenant-a', []);
        $store->publish($event);
        $this->expectException(\RuntimeException::class);
        $store->publish($event);
    }
}
