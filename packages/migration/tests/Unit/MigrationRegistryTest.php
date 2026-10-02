<?php

declare(strict_types=1);

namespace Evolve\Migration\Tests\Unit;

use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Migration\MigrationAction;
use Evolve\Migration\MigrationContributor;
use Evolve\Migration\MigrationDefinition;
use Evolve\Migration\MigrationIdentifier;
use Evolve\Migration\MigrationOwner;
use Evolve\Migration\MigrationRegistry;
use Evolve\Migration\MigrationTransactionMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MigrationRegistryTest extends TestCase
{
    public function test_identifiers_and_owner_keys(): void
    {
        foreach (['create_users', '20261002_create_users', 'billing.create_invoices'] as $value) {
            self::assertSame($value, (new MigrationIdentifier($value))->value());
        }
        foreach (['', 'One', 'one two', '.one', 'one.', 'one..two'] as $value) {
            try {
                new MigrationIdentifier($value);
                self::fail('Malformed identifier accepted.');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame('application', MigrationOwner::application()->key());
        self::assertSame('module:billing', MigrationOwner::module(new ComponentIdentifier('billing'))->key());
    }

    public function test_definition_rejects_negative_order_and_invalid_checksum(): void
    {
        $owner = MigrationOwner::application();
        $id = new MigrationIdentifier('create_users');
        $action = MigrationAction::fromCallable(static fn(): null => null);
        foreach ([[-1, str_repeat('a', 64)], [0, 'BAD']] as [$order, $checksum]) {
            try {
                new MigrationDefinition($owner, $id, $order, $checksum, $action);
                self::fail('Invalid definition accepted.');
            } catch (InvalidArgumentException) {
            }
        }
        $definition = new MigrationDefinition($owner, $id, 0, str_repeat('a', 64), $action);
        self::assertSame(MigrationTransactionMode::None, $definition->transactionMode());
        self::assertSame($action, $definition->action());
    }

    public function test_registry_sorts_by_order_owner_and_identifier_and_allows_same_local_id_across_owners(): void
    {
        $application = MigrationOwner::application();
        $module = MigrationOwner::module(new ComponentIdentifier('billing'));
        $registry = MigrationRegistry::fromContributors([
            $this->contributor('source.z', $module, [$this->definition($module, 'same', 2)]),
            $this->contributor('source.a', $application, [$this->definition($application, 'z', 2), $this->definition($application, 'same', 2), $this->definition($application, 'first', 1)]),
        ]);
        self::assertSame(['application:first', 'application:same', 'application:z', 'module:billing:same'], array_map(static fn(MigrationDefinition $definition): string => $definition->fullIdentity(), $registry->definitions()));
    }

    public function test_duplicate_contributor_and_full_identity_and_owner_mismatch_are_rejected(): void
    {
        $owner = MigrationOwner::application();
        $definition = $this->definition($owner, 'same', 0);
        $messages = [];
        foreach ([
            [$this->contributor('source', $owner, []), $this->contributor('source', $owner, [])],
            [$this->contributor('source.a', $owner, [$definition]), $this->contributor('source.b', $owner, [$definition])],
            [$this->contributor('source', MigrationOwner::module(new ComponentIdentifier('billing')), [$definition])],
        ] as $contributors) {
            try {
                MigrationRegistry::fromContributors($contributors);
                self::fail('Invalid contributor set accepted.');
            } catch (InvalidArgumentException $failure) {
                $messages[] = $failure->getMessage();
            }
        }
        self::assertSame([
            'Duplicate migration contributor identifier.',
            'Duplicate full migration identity.',
            'Migration contributor owner differs from definition owner.',
        ], $messages);
    }

    public function test_malformed_contributor_object_and_identifier_are_rejected(): void
    {
        $messages = [];
        foreach ([[new \stdClass()], [$this->contributor('Bad Contributor', MigrationOwner::application(), [])]] as $contributors) {
            try {
                MigrationRegistry::fromContributors($contributors);
                self::fail('Malformed contributor accepted.');
            } catch (InvalidArgumentException $failure) {
                $messages[] = $failure->getMessage();
            }
        }
        self::assertSame(['Migration contributors must implement MigrationContributor.', 'Migration identifier must contain lowercase ASCII segments.'], $messages);
    }

    private function definition(MigrationOwner $owner, string $id, int $order): MigrationDefinition
    {
        return new MigrationDefinition($owner, new MigrationIdentifier($id), $order, str_repeat('a', 64), MigrationAction::fromCallable(static fn(): null => null));
    }

    /** @param list<MigrationDefinition> $definitions */
    private function contributor(string $identifier, MigrationOwner $owner, array $definitions): MigrationContributor
    {
        return new class ($identifier, $owner, $definitions) implements MigrationContributor {
            /** @param list<MigrationDefinition> $definitions */
            public function __construct(private string $identifier, private MigrationOwner $owner, private array $definitions) {}
            public function identifier(): string
            {
                return $this->identifier;
            }
            public function owner(): MigrationOwner
            {
                return $this->owner;
            }
            public function definitions(): iterable
            {
                return $this->definitions;
            }
        };
    }
}
