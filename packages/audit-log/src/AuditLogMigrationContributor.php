<?php

declare(strict_types=1);

namespace Evolve\AuditLog;

use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Migration\MigrationAction;
use Evolve\Migration\MigrationContributor;
use Evolve\Migration\MigrationDefinition;
use Evolve\Migration\MigrationIdentifier;
use Evolve\Migration\MigrationOwner;
use Evolve\Migration\MigrationTransactionMode;
use RuntimeException;

/** Application-supplied contributor; registration does not execute the migration. */
final readonly class AuditLogMigrationContributor implements MigrationContributor
{
    public const CREATE_TABLE_SQL = 'CREATE TABLE evolve_audit_events ('
        . 'event_id VARCHAR(128) PRIMARY KEY, event_type VARCHAR(128) NOT NULL, '
        . 'occurred_at VARCHAR(32) NOT NULL, actor_id VARCHAR(128) NOT NULL, '
        . 'tenant_id VARCHAR(128) NULL, subject_id VARCHAR(128) NULL, '
        . 'correlation_id VARCHAR(128) NULL, payload_json TEXT NOT NULL)';

    public function identifier(): string
    {
        return 'audit-log';
    }

    public function owner(): MigrationOwner
    {
        return MigrationOwner::module(new ComponentIdentifier('evolvephp/audit-log'));
    }

    public function definitions(): iterable
    {
        yield new MigrationDefinition($this->owner(), new MigrationIdentifier('create_audit_events'),
            0, hash('sha256', self::CREATE_TABLE_SQL),
            MigrationAction::fromCallable(static function (?DatabaseConnection $connection): void {
                if ($connection === null) {
                    throw new RuntimeException('Audit migration requires a database connection.');
                }
                $connection->execute(new DatabaseStatement(self::CREATE_TABLE_SQL, [], 'audit-log.migrate'));
            }),
            MigrationTransactionMode::Database);
    }
}