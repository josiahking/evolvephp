<?php

declare(strict_types=1);

namespace Evolve\AuditLog;

use InvalidArgumentException;

/** Allowlist at the root and sensitive-key removal at every nested level. */
final readonly class RedactionPolicy
{
    /** @var array<string, true> */
    private array $allowed;

    /** @param list<string> $allowedFields */
    public function __construct(array $allowedFields)
    {
        $allowed = [];
        foreach ($allowedFields as $field) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $field) !== 1
                || self::sensitive($field) || isset($allowed[$field])) {
                throw new InvalidArgumentException('Invalid audit redaction allowlist.');
            }
            $allowed[$field] = true;
        }
        $this->allowed = $allowed;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        $safe = [];
        foreach ($payload as $key => $value) {
            if (isset($this->allowed[$key]) && !self::sensitive($key)) {
                $safe[$key] = is_array($value) ? self::removeSensitive($value) : $value;
            }
        }
        return $safe;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function removeSensitive(array $value): array
    {
        $safe = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && self::sensitive($key)) { continue; }
            $safe[$key] = is_array($item) ? self::removeSensitive($item) : $item;
        }
        return $safe;
    }

    private static function sensitive(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));
        return preg_match('/password|passwd|pwd|token|secret|credential|authorization|apikey|privatekey|session|cookie/', $normalized) === 1;
    }
}