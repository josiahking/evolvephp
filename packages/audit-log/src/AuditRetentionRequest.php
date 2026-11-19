<?php

declare(strict_types=1);

namespace Evolve\AuditLog;

use DateTimeImmutable;

/** Explicit authorized retention request; no background deletion. */
final readonly class AuditRetentionRequest
{
    public function __construct(
        public string $principal,
        public ?string $tenantId,
        public DateTimeImmutable $cutoff,
    ) {
        AuditEvent::assertToken($principal, 'retention principal');
        if ($tenantId !== null) { AuditEvent::assertToken($tenantId, 'tenant identity'); }
    }
}