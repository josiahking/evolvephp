<?php

declare(strict_types=1);

namespace Evolve\Migration;

use InvalidArgumentException;

/** @experimental This API may change before stable release. */
final readonly class MigrationDefinition
{
    public function __construct(
        private MigrationOwner $owner,
        private MigrationIdentifier $identifier,
        private int $order,
        private string $checksum,
        private MigrationAction $action,
        private MigrationTransactionMode $transactionMode = MigrationTransactionMode::None,
    ) {
        self::assertFacts($order, $checksum);
    }

    public static function assertFacts(int $order, string $checksum): void
    {
        if ($order < 0) {
            throw new InvalidArgumentException('Migration order must be non-negative.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/D', $checksum) !== 1) {
            throw new InvalidArgumentException('Migration checksum must be a lowercase 64-character hexadecimal fingerprint.');
        }
    }

    public function owner(): MigrationOwner
    {
        return $this->owner;
    }
    public function identifier(): MigrationIdentifier
    {
        return $this->identifier;
    }
    public function order(): int
    {
        return $this->order;
    }
    public function checksum(): string
    {
        return $this->checksum;
    }
    public function action(): MigrationAction
    {
        return $this->action;
    }
    public function transactionMode(): MigrationTransactionMode
    {
        return $this->transactionMode;
    }
    public function fullIdentity(): string
    {
        return $this->owner->key() . ':' . $this->identifier->value();
    }
}
