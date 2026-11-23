<?php

declare(strict_types=1);

namespace Evolve\Webhooks\Tests\Unit;

require_once __DIR__ . '/../../src/Signature.php';

use Evolve\Webhooks\Signature;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    public function testExactBodyAndHeadersAreBoundToSignature(): void
    {
        $signed = Signature::sign('{"ok":true}', 'delivery-1', 'key-1', 'secret-value', 1_700_000_000);
        self::assertTrue(Signature::verify('{"ok":true}', $signed, 'secret-value', 1_700_000_010, 300));
        self::assertFalse(Signature::verify('{"ok":false}', $signed, 'secret-value', 1_700_000_010, 300));
        $changed = $signed;
        $changed['X-Evolve-Webhook-Delivery'] = 'delivery-2';
        self::assertFalse(Signature::verify('{"ok":true}', $changed, 'secret-value', 1_700_000_010, 300));
        self::assertFalse(Signature::verify('{"ok":true}', $signed, 'secret-value', 1_700_000_301, 300));
        self::assertFalse(Signature::verify('{"ok":true}', $signed, 'secret-value', 1_699_999_699, 300));
    }

    public function testEmptySecretAndMissingHeadersFailClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Signature::sign('{}', 'delivery-1', 'key-1', '', 1_700_000_000);
    }

    public function testIdentityHeadersCannotBeChangedOrRemoved(): void
    {
        $signed = Signature::sign('{}', 'delivery-1', 'key-1', 'secret', 1000, 'event-1');
        self::assertTrue(Signature::verify('{}', $signed, 'secret', 1000, 60));
        foreach (['Idempotency-Key' => 'delivery-2', 'X-Evolve-Webhook-Event' => 'event-2'] as $name => $value) {
            $changed = $signed;
            $changed[$name] = $value;
            self::assertFalse(Signature::verify('{}', $changed, 'secret', 1000, 60));
            unset($changed[$name]);
            self::assertFalse(Signature::verify('{}', $changed, 'secret', 1000, 60));
        }
        $withoutEvent = $signed;
        unset($withoutEvent['X-Evolve-Webhook-Event']);
        self::assertFalse(Signature::verify("event-1\n{}", $withoutEvent, 'secret', 1000, 60));
    }
}
