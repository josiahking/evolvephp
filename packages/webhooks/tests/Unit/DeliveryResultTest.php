<?php

declare(strict_types=1);

namespace Evolve\Webhooks\Tests\Unit;

require_once __DIR__ . '/../../src/WebhookEvent.php';
require_once __DIR__ . '/../../src/DestinationPolicy.php';
require_once __DIR__ . '/../../src/WebhookSubscription.php';
require_once __DIR__ . '/../../src/Signature.php';
require_once __DIR__ . '/../../src/ResponsePolicy.php';
require_once __DIR__ . '/../../src/KeyProvider.php';
require_once __DIR__ . '/../../src/TrustedTransport.php';
require_once __DIR__ . '/../../src/DeliveryResult.php';
require_once __DIR__ . '/../../src/DeliveryAttempt.php';

use Evolve\Webhooks\DeliveryAttempt;
use Evolve\Webhooks\KeyProvider;
use Evolve\Webhooks\TrustedTransport;
use Evolve\Webhooks\WebhookEvent;
use Evolve\Webhooks\WebhookSubscription;
use PHPUnit\Framework\TestCase;

final class DeliveryResultTest extends TestCase
{
    public function testResponseStatusIsRetainedForPersistence(): void
    {
        $keys = new class implements KeyProvider {
            public function resolve(string $keyId, int $at): ?string
            {
                return $keyId === 'key-1' ? 'secret' : null;
            }
        };
        $transport = new class implements TrustedTransport {
            public function send(string $url, string $body, array $headers): int
            {
                return 429;
            }
        };
        $result = (new DeliveryAttempt($keys, $transport))->sendResult(
            new WebhookEvent('event-1', 'user.created', 'tenant-1', []),
            new WebhookSubscription('sub-1', 'user.created', 'tenant-1', 'https://example.com/hook', 'key-1'),
            'delivery-1',
            1000,
        );
        self::assertSame('retry', $result->outcome);
        self::assertSame(429, $result->status);
    }
}
