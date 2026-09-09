<?php

declare(strict_types=1);

namespace Evolve\DevTools\Adoption;

/**
 * @experimental
 */
enum DataMigrationState: string
{
    case LegacyAuthoritative = 'legacy_authoritative';
    case MigrationSyncing = 'migration_syncing';
    case EvolveAuthoritative = 'evolve_authoritative';
    case LegacyReadOnly = 'legacy_read_only';
    case LegacyRetired = 'legacy_retired';
}
