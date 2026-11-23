<?php

declare(strict_types=1);

namespace Evolve\Webhooks\Tests\Unit;

require_once __DIR__ . '/../../src/Signature.php';
require_once __DIR__ . '/../../src/ReplayStore.php';
require_once __DIR__ . '/../../src/KeyProvider.php';
require_once __DIR__ . '/../../src/Verifier.php';

use Evolve\Webhooks\KeyProvider;
use Evolve\Webhooks\ReplayStore;
use Evolve\Webhooks\Signature;
use Evolve\Webhooks\Verifier;
use PHPUnit\Framework\TestCase;

final class KeyBindingTest extends TestCase
{
    public function testUnknownKeyIdentifierFailsBeforeReplayClaim(): void
    {
        $keys = new class implements KeyProvider {
            public function resolve(string $keyId, int $at): ?string
            {
                return $keyId === 'key-2' ? 'secret' : null;
            }
        };
        $store = new class implements ReplayStore {
            public bool $called = false;
            public function claim(string $key, int $expiresAt): bool
            {
                $this->called = true;
                return true;
            }
        };
        $headers = Signature::sign('{}', 'delivery-1', 'key-1', 'secret', 1000);
        self::assertFalse((new Verifier($store, $keys))->verify('{}', $headers, 1000, 60));
        self::assertFalse($store->called);
    }

    public function testTamperedIdentityHeadersFailBeforeReplayClaim(): void
    {
        $keys = new class implements KeyProvider {
            public function resolve(string $keyId, int $at): ?string
            {
                return $keyId === 'key-1' ? 'secret' : null;
            }
        };
        $store = new class implements ReplayStore {
            public int $calls = 0;
            public function claim(string $key, int $expiresAt): bool
            {
                ++$this->calls;
                return true;
            }
        };
        $signed = Signature::sign('{}', 'delivery-1', 'key-1', 'secret', 1000, 'event-1');
        foreach (['Idempotency-Key' => 'delivery-2', 'X-Evolve-Webhook-Event' => 'event-2'] as $name => $value) {
            $changed = $signed;
            $changed[$name] = $value;
            self::assertFalse((new Verifier($store, $keys))->verify('{}', $changed, 1000, 60));
        }
        self::assertSame(0, $store->calls);
        self::assertTrue((new Verifier($store, $keys))->verify('{}', $signed, 1000, 60));
        self::assertSame(1, $store->calls);
    }

    public function testColonBearingKeyAndDeliveryIdentifiersHaveIndependentReplayClaims(): void
    {
        $keys = new class implements KeyProvider {
            public function resolve(string $keyId, int $at): ?string
            {
                return in_array($keyId, ['a:b', 'a'], true) ? 'secret' : null;
            }
        };
        $store = new class implements ReplayStore {
            /** @var array<string, true> */
            public array $seen = [];
            public function claim(string $key, int $expiresAt): bool
            {
                if (isset($this->seen[$key])) {
                    return false;
                }
                $this->seen[$key] = true;
                return true;
            }
        };
        $verifier = new Verifier($store, $keys);
        $first = Signature::sign('{}', 'c', 'a:b', 'secret', 1000);
        $second = Signature::sign('{}', 'b:c', 'a', 'secret', 1000);
        self::assertTrue($verifier->verify('{}', $first, 1000, 60));
        self::assertTrue($verifier->verify('{}', $second, 1000, 60));
        self::assertCount(2, $store->seen);
        self::assertFalse($verifier->verify('{}', $first, 1000, 60));
        self::assertFalse($verifier->verify('{}', $second, 1000, 60));
    }
}
