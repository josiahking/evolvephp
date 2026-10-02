<?php

declare(strict_types=1);

namespace Evolve\Migration;

/** @experimental This API may change before stable release. */
final readonly class AppliedMigration
{
    public function __construct(
        private MigrationOwner $owner,
        private MigrationIdentifier $identifier,
        private int $order,
        private string $checksum,
    ) {
        MigrationDefinition::assertFacts($order, $checksum);
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
    public function fullIdentity(): string
    {
        return $this->owner->key() . ':' . $this->identifier->value();
    }
}
