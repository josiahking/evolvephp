<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

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

/** Host runs these migrations explicitly; module registration never runs SQL. */
final readonly class WebhooksMigrationContributor implements MigrationContributor
{
    private const SQL = [
        'CREATE TABLE evolve_webhook_events (event_id VARCHAR(128) PRIMARY KEY, tenant_id VARCHAR(128) NOT NULL, event_type VARCHAR(128) NOT NULL, payload_json TEXT NOT NULL, metadata_json TEXT NOT NULL)',
        'CREATE TABLE evolve_webhook_subscriptions (subscription_id VARCHAR(128) PRIMARY KEY, tenant_id VARCHAR(128) NOT NULL, event_type VARCHAR(128) NOT NULL, destination VARCHAR(2048) NOT NULL, key_id VARCHAR(128) NOT NULL)',
        'CREATE TABLE evolve_webhook_deliveries (delivery_id VARCHAR(64) PRIMARY KEY, event_id VARCHAR(128) NOT NULL, subscription_id VARCHAR(128) NOT NULL, tenant_id VARCHAR(128) NOT NULL, state VARCHAR(16) NOT NULL, attempts INTEGER NOT NULL, lease_token VARCHAR(128) NULL, lease_until INTEGER NULL, next_attempt_at INTEGER NOT NULL, response_status INTEGER NULL)',
    ];

    public function identifier(): string
    {
        return 'webhooks';
    }
    public function owner(): MigrationOwner
    {
        return MigrationOwner::module(new ComponentIdentifier('evolvephp/webhooks'));
    }
    public function definitions(): iterable
    {
        yield new MigrationDefinition(
            $this->owner(),
            new MigrationIdentifier('create_webhook_tables'),
            0,
            hash('sha256', implode(';', self::SQL)),
            MigrationAction::fromCallable(static function (?DatabaseConnection $db): void {
                if ($db === null) {
                    throw new RuntimeException('Webhook migration requires a database connection.');
                }
                foreach (self::SQL as $sql) {
                    $db->execute(new DatabaseStatement($sql, [], 'webhooks.migrate'));
                }
            }),
            MigrationTransactionMode::Database,
        );
    }
}
