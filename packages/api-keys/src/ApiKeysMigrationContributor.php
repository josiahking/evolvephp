<?php

declare(strict_types=1);

namespace Evolve\ApiKeys;

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

final readonly class ApiKeysMigrationContributor implements MigrationContributor
{
    public const CREATE_TABLE_SQL = 'CREATE TABLE evolve_api_keys ('
        . 'identifier VARCHAR(32) PRIMARY KEY, digest VARCHAR(64) NOT NULL, '
        . 'principal VARCHAR(128) NOT NULL, tenant VARCHAR(128) NOT NULL, '
        . 'scopes_json TEXT NOT NULL, expires_at VARCHAR(32) NOT NULL, revoked_at VARCHAR(32) NULL)';

    public function identifier(): string
    {
        return 'api-keys';
    }
    public function owner(): MigrationOwner
    {
        return MigrationOwner::module(new ComponentIdentifier('evolvephp/api-keys'));
    }
    public function definitions(): iterable
    {
        yield new MigrationDefinition(
            $this->owner(),
            new MigrationIdentifier('create_api_keys'),
            0,
            hash('sha256', self::CREATE_TABLE_SQL),
            MigrationAction::fromCallable(static function (?DatabaseConnection $db): void {
                if ($db === null) {
                    throw new RuntimeException('API keys migration requires a database connection.');
                }
                $db->execute(new DatabaseStatement(self::CREATE_TABLE_SQL, [], 'api-keys.migrate'));
            }),
            MigrationTransactionMode::Database,
        );
    }
}
