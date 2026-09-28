<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Database;

use Evolve\DevTools\Database\DatabaseCapability;
use Evolve\DevTools\Database\DatabaseFamilyDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DatabaseFamilyDefinitionTest extends TestCase
{
    public function testCapabilityVocabularyIsExact(): void
    {
        self::assertSame(
            [
                'acid-transactions',
                'joins',
                'schema-flexibility',
                'strong-consistency',
                'eventual-consistency',
                'horizontal-scale',
                'write-intensive',
                'read-intensive',
                'low-latency',
                'high-throughput',
                'full-text-search',
                'vector-similarity',
                'graph-traversal',
                'time-series-retention',
                'geospatial',
                'ttl',
                'change-streams',
                'analytical-scans',
                'embedded-offline',
                'distributed-deployment',
                'managed-deployment',
                'immutable-history',
            ],
            $this->backingValues(DatabaseCapability::class),
        );
    }

    public function testDefinitionPreservesNamesAndCanonicalizesOrderedCollections(): void
    {
        $definition = new DatabaseFamilyDefinition(
            'custom-store',
            '  Custom Store  ',
            [
                DatabaseCapability::ReadIntensive,
                DatabaseCapability::AcidTransactions,
                DatabaseCapability::ManagedDeployment,
            ],
            ['ZetaDB', 'AlphaDB'],
            ['First trade-off.', 'Second trade-off.'],
        );

        self::assertSame('custom-store', $definition->identifier());
        self::assertSame('  Custom Store  ', $definition->displayName());
        self::assertSame(
            [
                DatabaseCapability::AcidTransactions,
                DatabaseCapability::ManagedDeployment,
                DatabaseCapability::ReadIntensive,
            ],
            $definition->capabilities(),
        );
        self::assertSame(['AlphaDB', 'ZetaDB'], $definition->exampleEngines());
        self::assertSame(['First trade-off.', 'Second trade-off.'], $definition->tradeOffs());
    }

    public function testDefinitionAllowsEmptyExampleList(): void
    {
        $definition = new DatabaseFamilyDefinition(
            'ledger',
            'Ledger',
            [DatabaseCapability::ImmutableHistory],
            [],
            ['Append-only workflows require explicit modelling.'],
        );

        self::assertSame([], $definition->exampleEngines());
    }

    public function testDefinitionRejectsInvalidIdentifier(): void
    {
        foreach (['', ' ', 'Not-Kebab', '-leading', 'trailing-', 'double--hyphen', 'with_underscore', 'has whitespace', '1-leading-digit'] as $identifier) {
            try {
                new DatabaseFamilyDefinition($identifier, 'Name', [DatabaseCapability::Joins], [], ['Trade-off.']);
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Database family identifier is invalid.', $exception->getMessage());

                continue;
            }

            self::fail(sprintf('Identifier "%s" should have been rejected.', $identifier));
        }
    }

    public function testDefinitionRejectsBlankDisplayName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database family display name must be non-empty.');

        new DatabaseFamilyDefinition('relational', ' ', [DatabaseCapability::Joins], [], ['Trade-off.']);
    }

    public function testDefinitionRejectsInvalidExampleEngineCollections(): void
    {
        foreach (
            [
                [['named' => 'PostgreSQL'], 'Database family example engines must be a list.'],
                [[123], 'Database family example engines may contain only non-empty strings.'],
                [[''], 'Database family example engines may contain only non-empty strings.'],
                [['PostgreSQL', 'PostgreSQL'], 'Database family example engines contain duplicate values.'],
            ] as [$exampleEngines, $message]
        ) {
            try {
                new DatabaseFamilyDefinition('relational', 'Relational', [DatabaseCapability::Joins], $exampleEngines, ['Trade-off.']);
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());

                continue;
            }

            self::fail('Example-engine collection should have been rejected.');
        }
    }

    public function testDefinitionRejectsInvalidTradeOffCollections(): void
    {
        foreach (
            [
                [['named' => 'Trade-off.'], 'Database family trade-offs must be a list.'],
                [[123], 'Database family trade-offs may contain only non-empty strings.'],
                [[''], 'Database family trade-offs may contain only non-empty strings.'],
                [[], 'Database family trade-offs must contain at least one value.'],
                [['Trade-off.', 'Trade-off.'], 'Database family trade-offs contain duplicate values.'],
            ] as [$tradeOffs, $message]
        ) {
            try {
                new DatabaseFamilyDefinition('relational', 'Relational', [DatabaseCapability::Joins], [], $tradeOffs);
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());

                continue;
            }

            self::fail('Trade-off collection should have been rejected.');
        }
    }

    public function testDefinitionRejectsInvalidCapabilityCollections(): void
    {
        foreach (
            [
                [['capability' => DatabaseCapability::Joins], 'Database family capabilities must be a list.'],
                [['joins'], 'Database family capabilities may contain only database capabilities.'],
                [[DatabaseCapability::Joins, DatabaseCapability::Joins], 'Database family capabilities contain duplicate capabilities.'],
            ] as [$capabilities, $message]
        ) {
            try {
                new DatabaseFamilyDefinition('relational', 'Relational', $capabilities, [], ['Trade-off.']);
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());

                continue;
            }

            self::fail('Capability collection should have been rejected.');
        }
    }

    /**
     * @param class-string<\BackedEnum> $enum
     *
     * @return list<int|string>
     */
    private function backingValues(string $enum): array
    {
        return array_map(
            static fn(\ReflectionEnumBackedCase $case): int|string => $case->getBackingValue(),
            (new \ReflectionEnum($enum))->getCases(),
        );
    }
}
