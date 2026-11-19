<?php

declare(strict_types=1);

namespace Evolve\AuditLog;

use Evolve\AuditLog\Exception\UnauthorizedAuditAccess;

/** Stateless execution service: attribution is supplied on each call. */
final readonly class AuditLog
{
    public function __construct(
        private AuditStore $store,
        private RedactionPolicy $redaction,
        private AuditAccessPolicy $access,
    ) {}

    public function record(AuditEvent $event): void
    {
        $this->store->append($event, $this->redaction->sanitize($event->payload));
    }

    /** @return list<AuditEvent> */
    public function read(AuditReadRequest $request): array
    {
        if (!$this->access->canRead($request->principal, $request->tenantId)) {
            throw new UnauthorizedAuditAccess('Audit read is not authorized.');
        }
        return $this->store->read($request);
    }

    public function purge(AuditRetentionRequest $request): int
    {
        if (!$this->access->canPurge($request->principal, $request->tenantId)) {
            throw new UnauthorizedAuditAccess('Audit retention is not authorized.');
        }
        return $this->store->purge($request);
    }
}