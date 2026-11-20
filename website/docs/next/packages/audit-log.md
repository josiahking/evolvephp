---
title: Audit Log
description: Explicit first-party audit events, storage, access and retention for EvolvePHP 2.
---

# Audit Log

The experimental `evolvephp/audit-log` package records application-emitted audit events. It is available as local source only; independent public package publication and Packagist registration have not been verified. Applications decide when to emit events and which Module SDK definitions to enable. No request, ORM, HTTP or authentication interception is installed.

Use a Composer path repository pointing to this source package and its first-party dependencies for a local preview. The package requires PHP `^8.4`, Contracts, DatabaseContracts, I18n, Migration and Module. It also directly requires PSR Container for its Module SDK factory signatures. The 36-package source inventory includes Audit Log. Its proposed split repository remains unverified.

## Compose and emit

Provide a `DatabaseConnection`, a `RedactionPolicy` with approved payload fields, and an application-owned `AuditAccessPolicy`. The Module SDK entry point `AuditLogModule` registers `AuditLog` for each execution and exposes `AuditLogMigrationContributor` as an application service. If the application does not include the module, no audit service or migration is registered. Direct construction is also supported:

~~~php
use Evolve\AuditLog\AuditAccessPolicy;
use Evolve\AuditLog\AuditEvent;
use Evolve\AuditLog\AuditLog;
use Evolve\AuditLog\AuditReadRequest;
use Evolve\AuditLog\AuditRetentionRequest;
use Evolve\AuditLog\RedactionPolicy;
use Evolve\AuditLog\SqlAuditStore;

$access = new class implements AuditAccessPolicy {
    public function canRead(string $principal, ?string $tenant): bool {
        return $principal === 'auditor-1' && $tenant === 'tenant-1';
    }
    public function canPurge(string $principal, ?string $tenant): bool {
        return $principal === 'retention-admin' && $tenant === 'tenant-1';
    }
};
$log = new AuditLog(new SqlAuditStore($connection), new RedactionPolicy(['outcome']), $access);
$log->record(new AuditEvent(
    'evt-001', 'user.login', new DateTimeImmutable('2026-10-29T10:00:00Z'),
    'user-1', 'tenant-1', 'subject-1', 'correlation-1', ['outcome' => 'success'],
));
$events = $log->read(new AuditReadRequest('auditor-1', 'tenant-1', 50));
~~~

`$connection` must be an application-supplied `DatabaseConnection`. The actor, tenant, subject and correlation identifiers are explicit inputs; no ambient context is inferred. Null tenant denotes global events, not all tenants. Event IDs are unique, and duplicate writes fail visibly. A single `AuditLog` instance stores no request-specific attribution; the Module SDK registers it with execution lifetime.

## Schema, access and retention

The application supplies `new AuditLogMigrationContributor()` to `MigrationRegistry::fromContributors()` and runs its migration through the host's Migration runner. It is module-owned and creates `evolve_audit_events`. Registration and boot never execute SQL. Migration failure remains visible. There is no automated rollback action; plan manual rollback and evidence export before deleting the table.

Reads and retention require an explicit `AuditAccessPolicy` decision for the stated principal and tenant. Reads apply a SQL tenant filter, a 1–100 result limit and a bounded offset. To delete only one tenant's records older than a chosen cutoff, call `$log->purge(new AuditRetentionRequest('retention-admin', 'tenant-1', $cutoff))`. No scheduled retention runs automatically. The host is responsible for legal retention choices.

The payload policy persists only allowlisted top-level keys and removes known sensitive key names recursively. Event construction rejects unsupported types, oversized strings, excessive nesting and payloads larger than 4096 encoded bytes. Key-based redaction cannot identify a secret placed inside a permitted ordinary string field; the emitter must exclude it. Write and transaction failures propagate by default. SQL storage is not tamper-proof. Applications can render event type labels with `AuditEventLabel`, an existing I18n `Translator` and an explicit `LocalizationContext`; localized text is not stored.

Audit Log has no automatic discovery, framework-wide dispatcher, direct Core dependency, network publication or public consumer availability. Migration and I18n bring Core transitively through their own Composer contracts. The host remains responsible for closing Core execution scopes and resetting connection adapters between worker executions.