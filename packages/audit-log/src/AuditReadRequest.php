<?php

declare(strict_types=1);

namespace Evolve\AuditLog;

use InvalidArgumentException;

/** Explicit principal and tenant scope; null tenant means global events only. */
final readonly class AuditReadRequest
{
    public function __construct(
        public string $principal,
        public ?string $tenantId,
        public int $limit = 50,
        public int $offset = 0,
    ) {
        AuditEvent::assertToken($principal, 'read principal');
        if ($tenantId !== null) { AuditEvent::assertToken($tenantId, 'tenant identity'); }
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 10000) {
            throw new InvalidArgumentException('Audit query bounds are invalid.');
        }
    }
}