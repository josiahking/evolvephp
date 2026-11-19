<?php

declare(strict_types=1);

namespace Evolve\AuditLog;

use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Module\Module;
use Psr\Container\ContainerInterface;
use RuntimeException;

/** Explicit Module SDK entry point; inert unless the host includes its definition. */
final class AuditLogModule implements Module
{
    public function register(ServiceDefinitionRegistrar $registrar): void
    {
        $registrar->registerApplication(
            SqlAuditStore::class,
            static fn(?ContainerInterface $services = null): SqlAuditStore =>
                new SqlAuditStore(($services ?? throw new RuntimeException('Audit module requires a service container.'))->get(DatabaseConnection::class)),
        );
        $registrar->registerApplication(
            AuditLogMigrationContributor::class,
            static fn(): AuditLogMigrationContributor => new AuditLogMigrationContributor(),
        );
        $registrar->registerExecution(
            AuditLog::class,
            static fn(?ContainerInterface $services = null): AuditLog =>
                new AuditLog(($services ?? throw new RuntimeException('Audit module requires a service container.'))->get(SqlAuditStore::class),
                    $services->get(RedactionPolicy::class), $services->get(AuditAccessPolicy::class)),
        );
    }

    public function boot(ComponentBootContext $context): void {}

    public function ready(): void {}

    public function shutdown(): void {}
}