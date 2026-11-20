<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

use InvalidArgumentException;
use JsonException;

final readonly class WebhookEvent
{
    private string $json;
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $tenantId,
        public array $payload,
        public array $metadata = [],
    ) {
        foreach ([$id, $type, $tenantId] as $value) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
                throw new InvalidArgumentException('Invalid webhook identity.');
            }
        }
        self::check($payload, 0);
        self::check($metadata, 0);
        try {
            $this->json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $metaJson = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid webhook data.');
        }
        if (strlen($this->json) > 65536 || strlen($metaJson) > 4096) {
            throw new InvalidArgumentException('Webhook data exceeds byte limits.');
        }
    }

    public function payloadJson(): string
    {
        return $this->json;
    }

    /** @param array<array-key, mixed> $data */
    private static function check(array $data, int $depth): void
    {
        if ($depth > 4 || count($data) > 64) {
            throw new InvalidArgumentException('Webhook data exceeds structure limits.');
        }
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $key) !== 1) {
                throw new InvalidArgumentException('Invalid webhook field.');
            }
            if (is_array($value)) {
                self::check($value, $depth + 1);
            } elseif ($value !== null && !is_bool($value) && !is_int($value)
                && !(is_float($value) && is_finite($value))
                && !(is_string($value) && strlen($value) <= 65536)) {
                throw new InvalidArgumentException('Unsupported webhook data value.');
            }
        }
    }
}
