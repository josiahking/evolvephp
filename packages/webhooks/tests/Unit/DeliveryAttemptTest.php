<?php

declare(strict_types=1);

namespace Evolve\Webhooks\Tests\Unit;

require_once __DIR__ . '/../../src/WebhookEvent.php';
require_once __DIR__ . '/../../src/DestinationPolicy.php';
require_once __DIR__ . '/../../src/Signature.php';
require_once __DIR__ . '/../../src/ResponsePolicy.php';
require_once __DIR__ . '/../../src/WebhookSubscription.php';
require_once __DIR__ . '/../../src/KeyProvider.php';
require_once __DIR__ . '/../../src/TrustedTransport.php';
require_once __DIR__ . '/../../src/DeliveryResult.php';
require_once __DIR__ . '/../../src/DeliveryAttempt.php';

use Evolve\Webhooks\DeliveryAttempt;
use Evolve\Webhooks\KeyProvider;
use Evolve\Webhooks\Signature;
use Evolve\Webhooks\TrustedTransport;
use Evolve\Webhooks\WebhookEvent;
use Evolve\Webhooks\WebhookSubscription;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeliveryAttemptTest extends TestCase
{
    public function testExplicitAttemptSignsExactBodyAndClassifiesResponse(): void
    {
        $event = new WebhookEvent('event-1', 'user.created', 'tenant-1', ['id' => 1]);
        $subscription = new WebhookSubscription('sub-1', 'user.created', 'tenant-1', 'https://example.com/hook', 'key-1');
        $keys = new class implements KeyProvider {
            public function resolve(string $keyId, int $at): ?string
            {
                return $keyId === 'key-1' ? 'secret' : null;
            }
        };
        $transport = new class implements TrustedTransport {
            public string $body = '';
            /** @var array<string, string> */
            public array $headers = [];
            public function send(string $url, string $body, array $headers): int
            {
                $this->body = $body;
                $this->headers = $headers;
                return 204;
            }
        };
        $attempt = new DeliveryAttempt($keys, $transport);
        self::assertSame('acknowledged', $attempt->send($event, $subscription, 'delivery-1', 1000));
        self::assertSame('{"id":1}', $transport->body);
        self::assertTrue(Signature::verify($transport->body, $transport->headers, 'secret', 1000, 60));
        self::assertSame('delivery-1', $transport->headers['Idempotency-Key']);
    }

    public function testCrossTenantAndMissingKeyFailClosed(): void
    {
        $event = new WebhookEvent('event-1', 'user.created', 'tenant-1', []);
        $other = new WebhookSubscription('sub-1', 'user.created', 'tenant-2', 'https://example.com/hook', 'key-1');
        $keys = new class implements KeyProvider {
            public function resolve(string $keyId, int $at): ?string
            {
                return null;
            }
        };
        $transport = new class implements TrustedTransport {
            public function send(string $url, string $body, array $headers): int
            {
                throw new RuntimeException('Network must not be called.');
            }
        };
        $attempt = new DeliveryAttempt($keys, $transport);
        $this->expectException(InvalidArgumentException::class);
        $attempt->send($event, $other, 'delivery-1', 1000);
    }

    public function testMatchingTenantMissingKeyFailsBeforeTransportWithoutDisclosure(): void
    {
        $event = new WebhookEvent('event-1', 'user.created', 'tenant-1', []);
        $subscription = new WebhookSubscription('sub-1', 'user.created', 'tenant-1', 'https://example.com/hook', 'secret-sentinel');
        $keys = new class implements KeyProvider {
            public bool $called = false;
            public function resolve(string $keyId, int $at): ?string
            {
                $this->called = true;
                return null;
            }
        };
        $transport = new class implements TrustedTransport {
            public bool $called = false;
            public function send(string $url, string $body, array $headers): int
            {
                $this->called = true;
                return 204;
            }
        };
        try {
            (new DeliveryAttempt($keys, $transport))->send($event, $subscription, 'delivery-1', 1000);
            self::fail('Expected unavailable signing key.');
        } catch (RuntimeException $failure) {
            self::assertSame('Webhook signing key is unavailable.', $failure->getMessage());
            self::assertStringNotContainsString('secret-sentinel', $failure->getMessage());
        }
        self::assertTrue($keys->called);
        self::assertFalse($transport->called);
    }

    public function testNetworkFailureIsUncertain(): void
    {
        $event = new WebhookEvent('event-1', 'user.created', 'tenant-1', []);
        $subscription = new WebhookSubscription('sub-1', 'user.created', 'tenant-1', 'https://example.com/hook', 'key-1');
        $keys = new class implements KeyProvider {
            public function resolve(string $keyId, int $at): ?string
            {
                return $keyId === 'key-1' ? 'secret' : null;
            }
        };
        $transport = new class implements TrustedTransport {
            public function send(string $url, string $body, array $headers): int
            {
                throw new RuntimeException('connection lost');
            }
        };
        self::assertSame('uncertain', (new DeliveryAttempt($keys, $transport))->send($event, $subscription, 'delivery-1', 1000));
    }
}
