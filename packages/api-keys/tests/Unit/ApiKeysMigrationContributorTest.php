<?php

declare(strict_types=1);

namespace Evolve\ApiKeys\Tests\Unit;

use Evolve\ApiKeys\ApiKeysMigrationContributor;
use Evolve\Migration\MigrationRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApiKeysMigrationContributorTest extends TestCase
{
    public function testMigrationIsOptInAndRequiresDatabase(): void
    {
        $contributor = new ApiKeysMigrationContributor();
        $definition = iterator_to_array($contributor->definitions())[0];
        self::assertSame('module:evolvephp/api-keys', $definition->owner()->key());
        self::assertCount(1, MigrationRegistry::fromContributors([$contributor])->definitions());
        $this->expectException(RuntimeException::class);
        $definition->action()->invoke(null);
    }

    public function testMigrationFailurePropagatesAndRequestsDatabaseTransaction(): void
    {
        $definition = iterator_to_array((new ApiKeysMigrationContributor())->definitions())[0];
        self::assertSame(\Evolve\Migration\MigrationTransactionMode::Database, $definition->transactionMode());
        $failure = new RuntimeException('migration rejected');
        $db = new class ($failure) implements \Evolve\Database\Contracts\DatabaseConnection {
            public function __construct(private RuntimeException $failure) {}
            public function execute(\Evolve\Database\Contracts\DatabaseStatement $statement): int
            {
                throw $this->failure;
            }
            public function query(\Evolve\Database\Contracts\DatabaseStatement $statement): iterable
            {
                return [];
            }
            public function transaction(callable $operation): mixed
            {
                return $operation($this);
            }
        };
        try {
            $definition->action()->invoke($db);
            self::fail('Expected migration storage failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }
}
