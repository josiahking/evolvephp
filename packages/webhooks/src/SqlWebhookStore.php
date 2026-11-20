<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseStatement;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** SQL writes and claims use the approved database transaction contract. */
final readonly class SqlWebhookStore
{
    public function __construct(private DatabaseConnection $db) {}

    public function addSubscription(WebhookSubscription $subscription): void
    {
        $this->db->transaction(static function (DatabaseConnection $db) use ($subscription): void {
            $count = $db->execute(new DatabaseStatement(
                'INSERT INTO evolve_webhook_subscriptions (subscription_id, tenant_id, event_type, destination, key_id) VALUES (:id, :tenant, :type, :destination, :key)',
                ['id' => $subscription->id, 'tenant' => $subscription->tenantId, 'type' => $subscription->eventType,
                    'destination' => $subscription->destination, 'key' => $subscription->keyId],
                'webhooks.subscribe',
            ));
            if ($count !== 1) {
                throw new RuntimeException('Webhook subscription was not stored.');
            }
        });
    }

    /** @return list<string> */
    public function publish(WebhookEvent $event): array
    {
        return $this->db->transaction(static function (DatabaseConnection $db) use ($event): array {
            $count = $db->execute(new DatabaseStatement(
                'INSERT INTO evolve_webhook_events (event_id, tenant_id, event_type, payload_json, metadata_json) VALUES (:id, :tenant, :type, :payload, :metadata)',
                ['id' => $event->id, 'tenant' => $event->tenantId, 'type' => $event->type,
                    'payload' => $event->payloadJson(), 'metadata' => json_encode($event->metadata, JSON_THROW_ON_ERROR)],
                'webhooks.publish',
            ));
            if ($count !== 1) {
                throw new RuntimeException('Webhook event was not stored.');
            }
            $rows = $db->query(new DatabaseStatement(
                'SELECT subscription_id FROM evolve_webhook_subscriptions WHERE tenant_id = :tenant AND event_type = :type ORDER BY subscription_id',
                ['tenant' => $event->tenantId, 'type' => $event->type],
                'webhooks.match',
            ));
            $ids = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['subscription_id'] ?? null)) {
                    throw new RuntimeException('Webhook subscription data is invalid.');
                }
                $id = hash('sha256', $event->id . "\n" . $row['subscription_id']);
                $count = $db->execute(new DatabaseStatement(
                    'INSERT INTO evolve_webhook_deliveries (delivery_id, event_id, subscription_id, tenant_id, state, attempts, next_attempt_at) VALUES (:id, :event, :subscription, :tenant, :state, :attempts, :next)',
                    ['id' => $id, 'event' => $event->id, 'subscription' => $row['subscription_id'],
                        'tenant' => $event->tenantId, 'state' => 'queued', 'attempts' => 0, 'next' => 0],
                    'webhooks.enqueue',
                ));
                if ($count !== 1) {
                    throw new RuntimeException('Webhook delivery was not queued.');
                }
                $ids[] = $id;
            }
            return $ids;
        });
    }

    public function claim(string $tenantId, string $deliveryId, string $leaseToken, int $now, int $maxAttempts): ?DeliveryWork
    {
        if ($maxAttempts < 1 || $maxAttempts > 20 || $now < 0
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $tenantId) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $deliveryId) !== 1
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $leaseToken) !== 1) {
            throw new InvalidArgumentException('Invalid webhook claim.');
        }
        return $this->db->transaction(static function (DatabaseConnection $db) use ($tenantId, $deliveryId, $leaseToken, $now, $maxAttempts): ?DeliveryWork {
            $reconciled = $db->execute(new DatabaseStatement(
                "UPDATE evolve_webhook_deliveries SET state = 'terminal', lease_token = NULL, lease_until = NULL, response_status = NULL, next_attempt_at = :now WHERE delivery_id = :id AND tenant_id = :tenant AND attempts >= :max AND ((state = 'inflight' AND lease_until < :now) OR (state = 'queued' AND next_attempt_at <= :now))",
                ['id' => $deliveryId, 'tenant' => $tenantId, 'now' => $now, 'max' => $maxAttempts],
                'webhooks.claim.reconcile',
            ));
            if ($reconciled > 1) {
                throw new RuntimeException('Webhook reconciliation affected multiple rows.');
            }
            if ($reconciled === 1) {
                return null;
            }
            $count = $db->execute(new DatabaseStatement(
                "UPDATE evolve_webhook_deliveries SET state = 'inflight', attempts = attempts + 1, lease_token = :lease, lease_until = :until WHERE delivery_id = :id AND tenant_id = :tenant AND attempts < :max AND ((state = 'queued' AND next_attempt_at <= :now) OR (state = 'inflight' AND lease_until < :now))",
                ['lease' => $leaseToken, 'until' => $now + 60, 'id' => $deliveryId, 'tenant' => $tenantId, 'max' => $maxAttempts, 'now' => $now],
                'webhooks.claim',
            ));
            if ($count === 0) {
                return null;
            }
            if ($count !== 1) {
                throw new RuntimeException('Webhook claim affected multiple rows.');
            }
            $rows = $db->query(new DatabaseStatement(
                'SELECT d.attempts, e.event_id, e.event_type, e.payload_json, e.metadata_json, s.subscription_id, s.destination, s.key_id FROM evolve_webhook_deliveries d JOIN evolve_webhook_events e ON e.event_id = d.event_id JOIN evolve_webhook_subscriptions s ON s.subscription_id = d.subscription_id WHERE d.delivery_id = :id AND d.tenant_id = :tenant AND e.tenant_id = :tenant AND s.tenant_id = :tenant',
                ['id' => $deliveryId, 'tenant' => $tenantId],
                'webhooks.claim.read',
            ));
            $row = null;
            foreach ($rows as $item) {
                if ($row !== null || !is_array($item)) {
                    throw new RuntimeException('Webhook claim data is invalid.');
                }
                $row = $item;
            }
            if ($row === null) {
                throw new RuntimeException('Webhook claim data is missing.');
            }
            try {
                $payload = json_decode((string) $row['payload_json'], true, 16, JSON_THROW_ON_ERROR);
                $metadata = json_decode((string) $row['metadata_json'], true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('Webhook event data is invalid.');
            }
            if (!is_array($payload) || !is_array($metadata)) {
                throw new RuntimeException('Webhook event data is invalid.');
            }
            return new DeliveryWork(
                $deliveryId,
                $tenantId,
                $leaseToken,
                (int) $row['attempts'],
                $maxAttempts,
                new WebhookEvent((string) $row['event_id'], (string) $row['event_type'], $tenantId, $payload, $metadata),
                new WebhookSubscription((string) $row['subscription_id'], (string) $row['event_type'], $tenantId, (string) $row['destination'], (string) $row['key_id']),
            );
        });
    }

    public function complete(DeliveryWork $work, string $outcome, ?int $status, int $nextAt): void
    {
        if (!in_array($outcome, ['acknowledged', 'terminal', 'retry', 'uncertain'], true) || $nextAt < 0) {
            throw new InvalidArgumentException('Invalid webhook outcome.');
        }
        $state = $outcome === 'acknowledged' ? 'acknowledged'
            : (($outcome === 'terminal' || $work->attempts >= $work->maxAttempts) ? 'terminal' : 'queued');
        $this->db->transaction(static function (DatabaseConnection $db) use ($work, $state, $status, $nextAt): void {
            $count = $db->execute(new DatabaseStatement(
                "UPDATE evolve_webhook_deliveries SET state = :state, response_status = :status, next_attempt_at = :next, lease_token = NULL, lease_until = NULL WHERE delivery_id = :id AND tenant_id = :tenant AND state = 'inflight' AND lease_token = :lease AND attempts = :attempts",
                ['state' => $state, 'status' => $status, 'next' => $nextAt, 'id' => $work->id,
                    'tenant' => $work->tenantId, 'lease' => $work->leaseToken, 'attempts' => $work->attempts],
                'webhooks.complete',
            ));
            if ($count !== 1) {
                throw new RuntimeException('Webhook completion lost its claim.');
            }
        });
    }
}
