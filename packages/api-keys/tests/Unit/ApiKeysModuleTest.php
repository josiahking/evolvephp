<?php

declare(strict_types=1);

namespace Evolve\ApiKeys\Tests\Unit;

use Evolve\ApiKeys\ApiKeys;
use Evolve\ApiKeys\ApiKeysMigrationContributor;
use Evolve\ApiKeys\ApiKeysModule;
use Evolve\ApiKeys\SqlApiKeyStore;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use PHPUnit\Framework\TestCase;

final class ApiKeysModuleTest extends TestCase
{
    public function testRegistrationIsExplicitAndDoesNotRunMigrations(): void
    {
        $registrar = new class implements ServiceDefinitionRegistrar {
            /** @var list<string> */ public array $application = [];
            /** @var list<string> */ public array $execution = [];
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
        self::assertSame([], $registrar->application);
        (new ApiKeysModule())->register($registrar);
        self::assertSame([SqlApiKeyStore::class, ApiKeysMigrationContributor::class], $registrar->application);
        self::assertSame([ApiKeys::class], $registrar->execution);
    }
}
