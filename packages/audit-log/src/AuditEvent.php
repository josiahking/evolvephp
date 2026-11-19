<?php

declare(strict_types=1);

namespace Evolve\AuditLog;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

/** Immutable, explicitly attributed event. Payload is bounded before redaction. */
final readonly class AuditEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $type,
        public DateTimeImmutable $occurredAt,
        public string $actorId,
        public ?string $tenantId = null,
        public ?string $subjectId = null,
        public ?string $correlationId = null,
        public array $payload = [],
    ) {
        self::assertToken($id, 'event identifier');
        self::assertToken($type, 'event type');
        self::assertToken($actorId, 'actor identity');
        foreach (['tenant identity' => $tenantId, 'subject identity' => $subjectId, 'correlation identity' => $correlationId] as $label => $value) {
            if ($value !== null) { self::assertToken($value, $label); }
        }
        if (count($payload) > 32) {
            throw new InvalidArgumentException('Audit payload exceeds the field limit.');
        }
        $count = 0;
        self::assertPayload($payload, 0, $count);
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid audit payload encoding.');
        }
        if (strlen($encoded) > 4096) {
            throw new InvalidArgumentException('Audit payload exceeds the byte limit.');
        }
    }

    public static function assertToken(string $value, string $label): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid audit ' . $label . '.');
        }
    }

    public function timestamp(): string
    {
        return $this->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** @param array<array-key, mixed> $value */
    private static function assertPayload(array $value, int $depth, int &$count): void
    {
        if ($depth > 3 || count($value) > 32) {
            throw new InvalidArgumentException('Audit payload nesting exceeds the limit.');
        }
        foreach ($value as $key => $item) {
            ++$count;
            if ($count > 64 || (is_string($key) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $key) !== 1)) {
                throw new InvalidArgumentException('Invalid or oversized audit payload structure.');
            }
            if (is_array($item)) {
                self::assertPayload($item, $depth + 1, $count);
            } elseif ($item !== null && !is_bool($item) && !is_int($item)
                && !(is_float($item) && is_finite($item))
                && !(is_string($item) && strlen($item) <= 1024)) {
                throw new InvalidArgumentException('Unsupported or oversized audit payload value.');
            }
        }
    }
}