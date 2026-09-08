<?php

declare(strict_types=1);

namespace Evolve\DevTools\Audit;

use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class AuditFinding
{
    private const IDENTIFIER_PATTERN = '/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/';

    /**
     * @param array<string|int, mixed> $evidence
     */
    public function __construct(
        private string $identifier,
        private AuditSeverity $severity,
        private string $message,
        private array $evidence,
    ) {
        if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw new InvalidArgumentException('Audit finding identifier is invalid.');
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException('Audit finding message must be non-empty.');
        }

        if (! self::isPlainData($evidence)) {
            throw new InvalidArgumentException('Audit finding evidence must contain only plain data.');
        }
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function severity(): AuditSeverity
    {
        return $this->severity;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return array<string|int, mixed>
     */
    public function evidence(): array
    {
        return $this->evidence;
    }

    private static function isPlainData(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $nestedValue) {
            if (! self::isPlainData($nestedValue)) {
                return false;
            }
        }

        return true;
    }
}
