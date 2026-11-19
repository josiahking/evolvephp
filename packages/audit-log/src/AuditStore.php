<?php

declare(strict_types=1);

namespace Evolve\AuditLog;

/** Replaceable persistence boundary; implementations must preserve tenant filtering. */
interface AuditStore
{
    /** @param array<string, mixed> $safePayload */
    public function append(AuditEvent $event, array $safePayload): void;

    /** @return list<AuditEvent> */
    public function read(AuditReadRequest $request): array;

    public function purge(AuditRetentionRequest $request): int;
}