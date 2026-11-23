<?php

declare(strict_types=1);

namespace Evolve\Webhooks\Tests\Unit;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Evolve\\Webhooks\\';
    if (str_starts_with($class, $prefix)) {
        require_once dirname(__DIR__, 2) . '/src/' . substr($class, strlen($prefix)) . '.php';
    }
});

use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Database\Pdo\PdoDatabaseConnection;
use Evolve\Webhooks\DeliveryAttempt;
use Evolve\Webhooks\KeyProvider;
use Evolve\Webhooks\SqlWebhookStore;
use Evolve\Webhooks\TrustedTransport;
use Evolve\Webhooks\WebhookAccessPolicy;
use Evolve\Webhooks\WebhookEvent;
use Evolve\Webhooks\Webhooks;
use Evolve\Webhooks\WebhooksMigrationContributor;
use Evolve\Webhooks\WebhookSubscription;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class WebhooksFlowTest extends TestCase
{
    public function testRetryThenAcknowledgePersistsResponseAndTenantScope(): void
    {
        $db = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
        foreach ((new WebhooksMigrationContributor())->definitions() as $definition) {
            $definition->action()->invoke($db);
        }
        $keys = new class implements KeyProvider {
            public function resolve(string $keyId, int $at): ?string
            {
                return $keyId === 'key-1' ? 'secret' : null;
            }
        };
        $transport = new class implements TrustedTransport {
            private int $calls = 0;
            public function send(string $url, string $body, array $headers): int
            {
                return ++$this->calls === 1 ? 429 : 204;
            }
        };
        $policy = new class implements WebhookAccessPolicy {
            public function canSubscribe(string $principal, string $tenantId): bool
            {
                return $principal === 'publisher' && $tenantId === 'tenant-1';
            }
            public function canPublish(string $principal, string $tenantId, string $eventType): bool
            {
                return $principal === 'publisher' && $tenantId === 'tenant-1' && $eventType === 'user.created';
            }
        };
        $webhooks = new Webhooks(new SqlWebhookStore($db), new DeliveryAttempt($keys, $transport), $policy);
        $webhooks->subscribe('publisher', new WebhookSubscription('sub-1', 'user.created', 'tenant-1', 'https://example.com/hook', 'key-1'));
        $ids = $webhooks->publish('publisher', new WebhookEvent('event-1', 'user.created', 'tenant-1', ['id' => 1]));
        self::assertCount(1, $ids);
        try {
            $webhooks->subscribe('publisher', new WebhookSubscription('sub-other', 'user.created', 'tenant-2', 'https://example.com/hook', 'key-1'));
            self::fail('Expected cross-tenant denial.');
        } catch (InvalidArgumentException) {
        }
        try {
            $webhooks->publish('publisher', new WebhookEvent('event-other', 'other.event', 'tenant-1', []));
            self::fail('Expected unsupported event denial.');
        } catch (InvalidArgumentException) {
        }
        self::assertNull($webhooks->deliver('tenant-2', $ids[0], 'lease-other', 1000));
        self::assertSame('retry', $webhooks->deliver('tenant-1', $ids[0], 'lease-1', 1000));
        self::assertNull($webhooks->deliver('tenant-1', $ids[0], 'lease-early', 1059));
        self::assertSame('acknowledged', $webhooks->deliver('tenant-1', $ids[0], 'lease-2', 1060));
        $rows = iterator_to_array($db->query(new DatabaseStatement(
            'SELECT state, attempts, response_status FROM evolve_webhook_deliveries WHERE delivery_id = :id',
            ['id' => $ids[0]],
        )));
        self::assertSame('acknowledged', $rows[0]['state']);
        self::assertSame(2, (int) $rows[0]['attempts']);
        self::assertSame(204, (int) $rows[0]['response_status']);
    }
}
