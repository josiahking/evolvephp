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

final class ReplayExpiryTest extends TestCase
{
    public function testClaimLastsUntilSignedTimestampExpires(): void
    {
        $store = new class implements ReplayStore {
            public int $expiresAt = 0;
            public function claim(string $key, int $expiresAt): bool
            {
                $this->expiresAt = $expiresAt;
                return true;
            }
        };
        $headers = Signature::sign('{}', 'delivery-1', 'key-1', 'secret', 1020);
        $keys = new class implements KeyProvider {
            public function resolve(string $keyId, int $at): ?string
            {
                return $keyId === 'key-1' ? 'secret' : null;
            }
        };
        self::assertTrue((new Verifier($store, $keys))->verify('{}', $headers, 1000, 60));
        self::assertSame(1080, $store->expiresAt);
    }
}
