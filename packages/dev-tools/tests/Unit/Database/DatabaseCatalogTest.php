<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Database;

use Evolve\DevTools\Database\DatabaseCapability;
use Evolve\DevTools\Database\DatabaseCatalog;
use Evolve\DevTools\Database\DatabaseFamilyDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DatabaseCatalogTest extends TestCase
{
    public function testCatalogSortsFamiliesByIdentifier(): void
    {
        $catalog = new DatabaseCatalog([$this->family('zeta'), $this->family('alpha')]);

        self::assertSame(['alpha', 'zeta'], $this->identifiers($catalog->families()));
    }

    public function testBuiltInCatalogContainsExactFamiliesInIdentifierOrder(): void
    {
        self::assertSame(
            [
                'analytical-warehouse',
                'distributed-sql',
                'document',
                'graph',
                'in-memory',
                'key-value',
                'ledger-immutable',
                'multi-model',
                'relational',
                'search-index',
                'time-series',
                'vector',
                'wide-column',
            ],
            $this->identifiers(DatabaseCatalog::builtIn()->families()),
        );
    }

    public function testBuiltInFamiliesExposeExactStaticGuidanceData(): void
    {
        $expected = [
            'analytical-warehouse' => [
                'Analytical Warehouse',
                ['analytical-scans', 'high-throughput', 'managed-deployment', 'read-intensive'],
                [],
            ],
            'distributed-sql' => [
                'Distributed SQL / NewSQL',
                ['acid-transactions', 'distributed-deployment', 'high-throughput', 'horizontal-scale', 'joins', 'managed-deployment', 'read-intensive', 'strong-consistency', 'write-intensive'],
                ['CockroachDB', 'TiDB'],
            ],
            'document' => [
                'Document',
                ['change-streams', 'geospatial', 'high-throughput', 'horizontal-scale', 'managed-deployment', 'read-intensive', 'schema-flexibility', 'write-intensive'],
                ['MongoDB'],
            ],
            'graph' => [
                'Graph',
                ['graph-traversal', 'managed-deployment', 'read-intensive', 'schema-flexibility'],
                ['Neo4j'],
            ],
            'in-memory' => [
                'In-Memory',
                ['high-throughput', 'low-latency', 'read-intensive', 'ttl', 'write-intensive'],
                ['Redis', 'Valkey'],
            ],
            'key-value' => [
                'Key-Value',
                ['distributed-deployment', 'high-throughput', 'horizontal-scale', 'low-latency', 'managed-deployment', 'read-intensive', 'ttl', 'write-intensive'],
                ['Redis', 'Valkey'],
            ],
            'ledger-immutable' => [
                'Ledger / Immutable',
                ['acid-transactions', 'immutable-history', 'strong-consistency'],
                [],
            ],
            'multi-model' => [
                'Multi-Model',
                ['managed-deployment', 'read-intensive', 'schema-flexibility'],
                [],
            ],
            'relational' => [
                'Relational / SQL',
                ['acid-transactions', 'analytical-scans', 'embedded-offline', 'joins', 'managed-deployment', 'read-intensive', 'strong-consistency'],
                ['MariaDB', 'MySQL', 'PostgreSQL', 'SQLite'],
            ],
            'search-index' => [
                'Search / Index',
                ['analytical-scans', 'full-text-search', 'high-throughput', 'managed-deployment', 'read-intensive', 'schema-flexibility'],
                ['Elasticsearch', 'OpenSearch'],
            ],
            'time-series' => [
                'Time-Series',
                ['analytical-scans', 'high-throughput', 'managed-deployment', 'time-series-retention', 'ttl', 'write-intensive'],
                ['InfluxDB', 'TimescaleDB'],
            ],
            'vector' => [
                'Vector',
                ['high-throughput', 'managed-deployment', 'read-intensive', 'vector-similarity'],
                ['Milvus', 'PostgreSQL + pgvector', 'Qdrant'],
            ],
            'wide-column' => [
                'Wide-Column',
                ['distributed-deployment', 'high-throughput', 'horizontal-scale', 'managed-deployment', 'read-intensive', 'write-intensive'],
                ['Cassandra', 'ScyllaDB'],
            ],
        ];

        foreach ($expected as $identifier => [$displayName, $capabilities, $exampleEngines]) {
            $family = $this->familyFromBuiltIn($identifier);

            self::assertSame($identifier, $family->identifier());
            self::assertSame($displayName, $family->displayName());
            self::assertSame($capabilities, $this->capabilityValues($family->capabilities()));
            self::assertSame($exampleEngines, $family->exampleEngines());
            self::assertNotSame([], $family->tradeOffs());
        }
    }

    public function testBuiltInEmptyExampleListsStayEmpty(): void
    {
        self::assertSame([], $this->familyFromBuiltIn('analytical-warehouse')->exampleEngines());
        self::assertSame([], $this->familyFromBuiltIn('ledger-immutable')->exampleEngines());
        self::assertSame([], $this->familyFromBuiltIn('multi-model')->exampleEngines());
    }

    public function testCatalogRejectsNonListFamilies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database catalog families must be a list.');

        new DatabaseCatalog(['named' => $this->family('relational')]);
    }

    public function testCatalogRequiresAtLeastOneFamily(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database catalog must contain at least one family.');

        new DatabaseCatalog([]);
    }

    public function testCatalogRejectsWrongFamilyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database catalog may contain only database family definitions.');

        new DatabaseCatalog(['relational']);
    }

    public function testCatalogRejectsDuplicateFamilyIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database catalog contains duplicate family identifiers.');

        new DatabaseCatalog([$this->family('relational'), $this->family('relational')]);
    }

    public function testExplicitCustomFamilyWorksWithoutAdvisorCoreChanges(): void
    {
        $custom = new DatabaseFamilyDefinition(
            'custom-oltp',
            'Custom OLTP',
            [DatabaseCapability::AcidTransactions, DatabaseCapability::Joins],
            ['CustomDB'],
            ['Requires application-owned operational review.'],
        );

        $catalog = new DatabaseCatalog([$custom]);

        self::assertSame([$custom], $catalog->families());
    }

    private function familyFromBuiltIn(string $identifier): DatabaseFamilyDefinition
    {
        foreach (DatabaseCatalog::builtIn()->families() as $family) {
            if ($family->identifier() === $identifier) {
                return $family;
            }
        }

        self::fail('Expected built-in family was not found.');
    }

    private function family(string $identifier): DatabaseFamilyDefinition
    {
        return new DatabaseFamilyDefinition(
            $identifier,
            ucfirst($identifier),
            [DatabaseCapability::ReadIntensive],
            [],
            ['Trade-off.'],
        );
    }

    /**
     * @param list<DatabaseFamilyDefinition> $families
     *
     * @return list<string>
     */
    private function identifiers(array $families): array
    {
        return array_map(
            static fn(DatabaseFamilyDefinition $family): string => $family->identifier(),
            $families,
        );
    }

    /**
     * @param list<DatabaseCapability> $capabilities
     *
     * @return list<string>
     */
    private function capabilityValues(array $capabilities): array
    {
        return array_map(
            static fn(DatabaseCapability $capability): string => $capability->value,
            $capabilities,
        );
    }
}
