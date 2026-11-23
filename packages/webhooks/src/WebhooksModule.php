<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Module\Module;
use Psr\Container\ContainerInterface;
use RuntimeException;

/** Inert until a host explicitly supplies its ModuleDefinition. */
final class WebhooksModule implements Module
{
    public function register(ServiceDefinitionRegistrar $registrar): void
    {
        $registrar->registerApplication(
            SqlWebhookStore::class,
            static fn(?ContainerInterface $services = null): SqlWebhookStore
                => new SqlWebhookStore(($services ?? throw new RuntimeException('Webhook services are unavailable.'))->get(DatabaseConnection::class)),
        );
        $registrar->registerApplication(
            WebhooksMigrationContributor::class,
            static fn(): WebhooksMigrationContributor => new WebhooksMigrationContributor(),
        );
        $registrar->registerExecution(
            DeliveryAttempt::class,
            static fn(?ContainerInterface $services = null): DeliveryAttempt
                => new DeliveryAttempt(
                    ($services ?? throw new RuntimeException('Webhook services are unavailable.'))->get(KeyProvider::class),
                    $services->get(TrustedTransport::class),
                ),
        );
        $registrar->registerExecution(
            Webhooks::class,
            static fn(?ContainerInterface $services = null): Webhooks
                => new Webhooks(
                    ($services ?? throw new RuntimeException('Webhook services are unavailable.'))->get(SqlWebhookStore::class),
                    $services->get(DeliveryAttempt::class),
                    $services->get(WebhookAccessPolicy::class),
                ),
        );
    }

    public function boot(ComponentBootContext $context): void {}
    public function ready(): void {}
    public function shutdown(): void {}
}
