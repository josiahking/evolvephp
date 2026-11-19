<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Evolve\AuditLog\AuditEvent;
use Evolve\AuditLog\AuditLogMigrationContributor;
use Evolve\AuditLog\AuditLog;
use Evolve\AuditLog\AuditReadRequest;
use Evolve\AuditLog\RedactionPolicy;
use Evolve\AuditLog\SqlAuditStore;
use Evolve\Migration\MigrationRegistry;

$required = [AuditEvent::class, AuditLog::class, AuditReadRequest::class, RedactionPolicy::class, SqlAuditStore::class, MigrationRegistry::class];
foreach ($required as $symbol) {
    if (!class_exists($symbol)) {
        throw new RuntimeException('Missing installed symbol: ' . $symbol);
    }
}
$event = new AuditEvent('consumer-event-1', 'user.login', new DateTimeImmutable('2026-10-29T10:00:00Z'), 'actor-1', 'tenant-1', payload: ['outcome' => 'success', 'password' => 'secret']);
$safe = (new RedactionPolicy(['outcome']))->sanitize($event->payload);
if ($safe !== ['outcome' => 'success']) {
    throw new RuntimeException('Consumer redaction failed.');
}
if (count(MigrationRegistry::fromContributors([new AuditLogMigrationContributor()])->definitions()) !== 1) {
    throw new RuntimeException('Consumer migration discovery failed.');
}
echo "Independent audit-log consumer passed\n";