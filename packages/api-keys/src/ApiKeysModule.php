<?php

declare(strict_types=1);

namespace Evolve\ApiKeys;

use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Module\Module;
use Psr\Container\ContainerInterface;
use RuntimeException;

/** Inert until the host supplies an explicit ModuleDefinition. */
final class ApiKeysModule implements Module
{
    public function register(ServiceDefinitionRegistrar $registrar): void
    {
        $registrar->registerApplication(
            SqlApiKeyStore::class,
            static fn(?ContainerInterface $services = null): SqlApiKeyStore
                => new SqlApiKeyStore(($services ?? throw new RuntimeException('API key services unavailable.'))->get(DatabaseConnection::class)),
        );
        $registrar->registerApplication(
            ApiKeysMigrationContributor::class,
            static fn(): ApiKeysMigrationContributor => new ApiKeysMigrationContributor(),
        );
        $registrar->registerExecution(
            ApiKeys::class,
            static fn(?ContainerInterface $services = null): ApiKeys
                => new ApiKeys(
                    ($services ?? throw new RuntimeException('API key services unavailable.'))->get(SqlApiKeyStore::class),
                    $services->get(ApiKeyAccessPolicy::class),
                ),
        );
    }

    public function boot(ComponentBootContext $context): void {}
    public function ready(): void {}
    public function shutdown(): void {}
}
