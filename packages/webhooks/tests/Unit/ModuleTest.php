<?php

declare(strict_types=1);

namespace Evolve\Webhooks\Tests\Unit;

require_once __DIR__ . '/../../src/WebhooksModule.php';

use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Webhooks\DeliveryAttempt;
use Evolve\Webhooks\SqlWebhookStore;
use Evolve\Webhooks\Webhooks;
use Evolve\Webhooks\WebhooksMigrationContributor;
use Evolve\Webhooks\WebhooksModule;
use PHPUnit\Framework\TestCase;

final class ModuleTest extends TestCase
{
    public function testRegistrationIsExplicitAndDoesNoWork(): void
    {
        $registrar = new class implements ServiceDefinitionRegistrar {
            /** @var list<string> */
            public array $application = [];
            /** @var list<string> */
            public array $execution = [];
            public function registerApplication(string $id, callable $factory): void
            {
                $this->application[] = $id;
            }
            public function registerExecution(string $id, callable $factory): void
            {
                $this->execution[] = $id;
            }
            public function registerTransient(string $id, callable $factory): void {}
        };
        (new WebhooksModule())->register($registrar);
        self::assertSame([SqlWebhookStore::class, WebhooksMigrationContributor::class], $registrar->application);
        self::assertSame([DeliveryAttempt::class, Webhooks::class], $registrar->execution);
    }
}
