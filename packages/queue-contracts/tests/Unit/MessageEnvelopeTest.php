<?php

declare(strict_types=1);

namespace Evolve\Queue\Contracts\Tests\Unit;

use Evolve\Queue\Contracts\MessageEnvelope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SensitiveParameter;

final class MessageEnvelopeTest extends TestCase
{
    public function test_envelope_preserves_opaque_payload_and_metadata_exactly(): void
    {
        $payload = "\0payload\xff";
        $metadata = array_combine([' X-Key ', 'second'], [' value ', '']);
        $envelope = new MessageEnvelope($payload, $metadata);

        self::assertSame($payload, $envelope->payload());
        $this->assertSameValue($metadata, $envelope->metadata());
        $this->assertSameValue([' X-Key ', 'second'], array_keys($envelope->metadata()));
    }

    public function test_empty_payload_and_default_metadata_are_permitted(): void
    {
        $envelope = new MessageEnvelope('');

        self::assertSame('', $envelope->payload());
        self::assertSame([], $envelope->metadata());
    }

    public function test_metadata_must_have_string_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MessageEnvelope('payload-secret', [123 => 'metadata-secret']);
    }

    public function test_metadata_must_have_string_values(): void
    {
        try {
            new MessageEnvelope('payload-secret', ['key-secret' => 123]);
            self::fail('Expected invalid metadata value to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('payload-secret', $exception->getMessage());
            self::assertStringNotContainsString('key-secret', $exception->getMessage());
            self::assertStringNotContainsString('123', $exception->getMessage());
        }
    }

    public function test_constructor_marks_sensitive_inputs_and_debug_output_redacts_them(): void
    {
        $constructor = (new ReflectionClass(MessageEnvelope::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertSame(['payload', 'metadata'], array_map(static fn($parameter) => $parameter->getName(), $constructor->getParameters()));
        self::assertCount(1, $constructor->getParameters()[0]->getAttributes(SensitiveParameter::class));
        self::assertCount(1, $constructor->getParameters()[1]->getAttributes(SensitiveParameter::class));

        $envelope = new MessageEnvelope('secret-payload', ['secret-key' => 'secret-value']);

        self::assertSame(['payload' => '[REDACTED]', 'metadata' => '[REDACTED]'], $envelope->__debugInfo());
        self::assertStringNotContainsString('secret-payload', print_r($envelope, true));
        self::assertStringNotContainsString('secret-key', print_r($envelope, true));
        self::assertStringNotContainsString('secret-value', print_r($envelope, true));
        self::assertFalse((new ReflectionClass(MessageEnvelope::class))->hasMethod('__toString'));
    }

    private function assertSameValue(mixed $expected, mixed $actual): void
    {
        self::assertSame($expected, $actual);
    }
}
