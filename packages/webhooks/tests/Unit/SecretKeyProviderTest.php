<?php

declare(strict_types=1);

namespace Evolve\Webhooks\Tests\Unit;

require_once __DIR__ . '/../../src/KeyProvider.php';
require_once __DIR__ . '/../../src/SecretKeyProvider.php';

use Evolve\Secret\Contracts\SecretName;
use Evolve\Secret\Contracts\SecretResolver;
use Evolve\Secret\Contracts\SecretValue;
use Evolve\Webhooks\SecretKeyProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SecretKeyProviderTest extends TestCase
{
    public function testOnlyExplicitCurrentKeyIsResolved(): void
    {
        $resolver = new class implements SecretResolver {
            public function resolve(SecretName $name): ?SecretValue
            {
                return $name->value() === 'webhook.key-1' ? new SecretValue('secret') : null;
            }
        };
        $keys = new SecretKeyProvider($resolver, 'key-1', 'webhook.key-1', 2000);
        self::assertSame('secret', $keys->resolve('key-1', 1000));
        self::assertNull($keys->resolve('old-key', 1000));
        self::assertNull($keys->resolve('key-1', 2001));
        try {
            new SecretKeyProvider($resolver, 'key-1', 'webhook.key-1', 0);
            self::fail('Expected invalid expiry.');
        } catch (InvalidArgumentException) {
        }
    }
}
