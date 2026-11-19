# EvolvePHP Audit Log

`evolvephp/audit-log` provides explicit application-owned audit event recording, tenant-scoped SQL persistence, authorized reads and explicit retention for EvolvePHP 2. It is an experimental local source package; it is not independently published or registered on Packagist.

Explicit application audit events, tenant-scoped SQL storage, authorized reads and retention.

EvolvePHP 2 is pre-release. This package is not yet independently published. The canonical source is the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

The package depends on `evolvephp/contracts`, `evolvephp/database-contracts`, `evolvephp/i18n`, `evolvephp/migration`, `evolvephp/module`. It also directly requires `psr/container` for its Module SDK service factory signatures. The repository source inventory includes this package. For a local preview, use an application-owned Composer path repository; public package installation is not available. PHP `^8.4` is required. `LICENSE.md` carries the root BSD-3-Clause license byte for byte.

## Composition

Applications opt in by supplying an `AuditLogModule` through the Module SDK. The module registers an application-scoped `SqlAuditStore`, an application-scoped `AuditLogMigrationContributor`, and an execution-scoped `AuditLog`. The host must register a `DatabaseConnection`, `RedactionPolicy` and `AuditAccessPolicy` in its container. Omitting the module leaves the application inert. Registration and boot perform no migration or database write.

An application may also construct `AuditLog` directly:

~~~php
use Evolve\AuditLog\{AuditAccessPolicy, AuditEvent, AuditLog, AuditReadRequest, RedactionPolicy, SqlAuditStore};

$policy = new class implements AuditAccessPolicy {
    public function canRead(string $principal, ?string $tenant): bool {
        return $principal === 'auditor-1' && $tenant === 'tenant-1';
    }
    public function canPurge(string $principal, ?string $tenant): bool {
        return $principal === 'retention-admin' && $tenant === 'tenant-1';
    }
};
$log = new AuditLog(new SqlAuditStore($connection), new RedactionPolicy(['outcome']), $policy);
$log->record(new AuditEvent(
    'evt-001', 'user.login', new DateTimeImmutable('2026-10-29T10:00:00Z'),
    'user-1', 'tenant-1', 'subject-1', 'correlation-1', ['outcome' => 'success'],
));
$events = $log->read(new AuditReadRequest('auditor-1', 'tenant-1', 50));
~~~

`$connection` is an application-supplied `DatabaseConnection`. The event carries actor, tenant, subject and correlation values explicitly; no ambient request, tenant or authentication context is read. Event types remain stable tokens. For localized presentation, construct `AuditEventLabel` with an existing I18n `Translator` and pass an explicit `LocalizationContext`; labels are not persisted.

## Schema and retention

Pass `new AuditLogMigrationContributor()` explicitly to `MigrationRegistry::fromContributors()` and execute the resulting plan through the host's Migration runner and history/lock setup. The contributor owns the `module:evolvephp/audit-log` migration and requires a database connection. Neither module registration nor boot runs migrations. The table has a primary key on event ID; duplicate IDs raise `DuplicateAuditEvent`. The migration has no automatic down action. Before manual schema removal, export retained evidence and review legal retention obligations.

Retention is caller-triggered only. `AuditLog::purge(new AuditRetentionRequest($principal, $tenantId, $cutoff))` requires `canPurge` authorization and deletes only that tenant's events older than the UTC cutoff. Null tenant selects global events only. No scheduler or implicit deletion is installed.

## Security and failure behavior

`RedactionPolicy` requires an explicit top-level field allowlist. It drops unlisted fields and known sensitive field names at every nested level before SQL persistence. Invalid sensitive allowlist entries fail construction. This is key-based filtering, not inspection of arbitrary string contents: applications must never put credentials in approved fields. Payloads are validated and bounded to 4096 encoded bytes, at most 64 total elements and depth 3. SQL parameters bind all event values.

Every read and purge requires a caller-owned `AuditAccessPolicy` decision for the stated principal and tenant. Queries enforce the same tenant in SQL and reject rows outside it. Read limits are 1–100 and offsets 0–10,000. Null tenant selects global events only. Write failures propagate; writes and retention execute inside explicit database transactions. The module holds no actor, tenant or correlation state in application singletons, and `AuditLog` is execution-scoped when registered through the Module SDK. Callers must still close their Core execution scope and reset their database adapter as their host requires.

This package does not intercept HTTP, ORM, authentication or other framework operations. It provides no tamper-proof guarantee, automatic discovery, automatic migration, automatic retention, public package installation or release readiness.