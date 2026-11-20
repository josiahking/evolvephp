<?php

declare(strict_types=1);

namespace Evolve\Webhooks\Tests\Unit;

require_once __DIR__ . '/../../src/Signature.php';
require_once __DIR__ . '/../../src/WebhookEvent.php';
require_once __DIR__ . '/../../src/DestinationPolicy.php';
require_once __DIR__ . '/../../src/ReplayStore.php';
require_once __DIR__ . '/../../src/KeyProvider.php';
require_once __DIR__ . '/../../src/Verifier.php';
require_once __DIR__ . '/../../src/ResponsePolicy.php';

use Evolve\Webhooks\DestinationPolicy;
use Evolve\Webhooks\KeyProvider;
use Evolve\Webhooks\ReplayStore;
use Evolve\Webhooks\ResponsePolicy;
use Evolve\Webhooks\Signature;
use Evolve\Webhooks\Verifier;
use Evolve\Webhooks\WebhookEvent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContractsTest extends TestCase
{
    public function testEventValidationAndBoundedJson(): void
    {
        $event = new WebhookEvent('event-1', 'user.created', 'tenant-1', ['id' => 12], ['source' => 'app']);
        self::assertSame('{"id":12}', $event->payloadJson());
        foreach (['bad id', '', '../x'] as $id) {
            try {
                new WebhookEvent($id, 'user.created', 'tenant-1', []);
                self::fail('Expected invalid identifier');
            } catch (InvalidArgumentException) {
            }
        }
        foreach ([['x' => new \stdClass()], ['x' => str_repeat('x', 65537)]] as $payload) {
            try {
                new WebhookEvent('event-1', 'user.created', 'tenant-1', $payload);
                self::fail('Expected invalid payload');
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testDestinationPolicyRejectsUnsafeTargets(): void
    {
        foreach (['http://example.com/hook', 'https://user:pass@example.com/hook', 'https://127.0.0.1/hook', 'https://169.254.169.254/', 'https://[::1]/', 'https://example.com:8080/hook', 'https://localhost/hook'] as $url) {
            try {
                DestinationPolicy::assertSafeUrl($url);
                self::fail('Expected unsafe destination');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame('https://example.com/hook', DestinationPolicy::assertSafeUrl('https://example.com/hook'));
    }

    public function testReplayProtectionNeedsAtomicStoreAndRejectsDuplicates(): void
    {
        $headers = Signature::sign('{}', 'delivery-1', 'key-1', 'secret', 1000);
        $store = new class implements ReplayStore {
            /** @var array<string, bool> */
            private array $seen = [];
            public function claim(string $key, int $expiresAt): bool
            {
                if (isset($this->seen[$key])) {
                    return false;
                }
                $this->seen[$key] = true;
                return true;
            }
        };
        $keys = new class implements KeyProvider {
            public function resolve(string $keyId, int $at): ?string
            {
                return $keyId === 'key-1' ? 'secret' : null;
            }
        };
        self::assertTrue((new Verifier($store, $keys))->verify('{}', $headers, 1001, 60));
        self::assertFalse((new Verifier($store, $keys))->verify('{}', $headers, 1001, 60));
        self::assertFalse((new Verifier(null, $keys))->verify('{}', $headers, 1001, 60));
        $failing = new class implements ReplayStore {
            public function claim(string $key, int $expiresAt): bool
            {
                throw new RuntimeException('store unavailable');
            }
        };
        self::assertFalse((new Verifier($failing, $keys))->verify('{}', $headers, 1001, 60));
    }

    public function testDeliveryResponseClassification(): void
    {
        self::assertSame('acknowledged', ResponsePolicy::classify(204));
        self::assertSame('terminal', ResponsePolicy::classify(302));
        self::assertSame('terminal', ResponsePolicy::classify(400));
        self::assertSame('retry', ResponsePolicy::classify(429));
        self::assertSame('retry', ResponsePolicy::classify(503));
    }
}
