<?php

declare(strict_types=1);

namespace Evolve\DevTools\Database;

use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class DatabaseCatalog
{
    /**
     * @var list<DatabaseFamilyDefinition>
     */
    private array $families;

    /**
     * @param array<mixed> $families
     */
    public function __construct(array $families)
    {
        if (! array_is_list($families)) {
            throw new InvalidArgumentException('Database catalog families must be a list.');
        }

        if ($families === []) {
            throw new InvalidArgumentException('Database catalog must contain at least one family.');
        }

        $identifiers = [];

        foreach ($families as $family) {
            if (! $family instanceof DatabaseFamilyDefinition) {
                throw new InvalidArgumentException('Database catalog may contain only database family definitions.');
            }

            if (isset($identifiers[$family->identifier()])) {
                throw new InvalidArgumentException('Database catalog contains duplicate family identifiers.');
            }

            $identifiers[$family->identifier()] = true;
        }

        usort(
            $families,
            static fn(DatabaseFamilyDefinition $first, DatabaseFamilyDefinition $second): int => $first->identifier() <=> $second->identifier(),
        );

        $this->families = $families;
    }

    /**
     * @return list<DatabaseFamilyDefinition>
     */
    public function families(): array
    {
        return $this->families;
    }

    public static function builtIn(): self
    {
        return new self(
            [
                new DatabaseFamilyDefinition(
                    'relational',
                    'Relational / SQL',
                    [
                        DatabaseCapability::AcidTransactions,
                        DatabaseCapability::Joins,
                        DatabaseCapability::StrongConsistency,
                        DatabaseCapability::ReadIntensive,
                        DatabaseCapability::AnalyticalScans,
                        DatabaseCapability::EmbeddedOffline,
                        DatabaseCapability::ManagedDeployment,
                    ],
                    ['MariaDB', 'MySQL', 'PostgreSQL', 'SQLite'],
                    [
                        'Horizontal partitioning may require additional product or architectural support.',
                        'Rigid relational schemas can be inconvenient for highly variable documents.',
                    ],
                ),
                new DatabaseFamilyDefinition(
                    'distributed-sql',
                    'Distributed SQL / NewSQL',
                    [
                        DatabaseCapability::AcidTransactions,
                        DatabaseCapability::Joins,
                        DatabaseCapability::StrongConsistency,
                        DatabaseCapability::HorizontalScale,
                        DatabaseCapability::ReadIntensive,
                        DatabaseCapability::WriteIntensive,
                        DatabaseCapability::HighThroughput,
                        DatabaseCapability::DistributedDeployment,
                        DatabaseCapability::ManagedDeployment,
                    ],
                    ['CockroachDB', 'TiDB'],
                    [
                        'Distributed-system operational complexity must be planned for explicitly.',
                        'Cross-region consistency and transaction semantics may increase latency or cost.',
                    ],
                ),
                new DatabaseFamilyDefinition(
                    'document',
                    'Document',
                    [
                        DatabaseCapability::SchemaFlexibility,
                        DatabaseCapability::HorizontalScale,
                        DatabaseCapability::ReadIntensive,
                        DatabaseCapability::WriteIntensive,
                        DatabaseCapability::HighThroughput,
                        DatabaseCapability::ChangeStreams,
                        DatabaseCapability::Geospatial,
                        DatabaseCapability::ManagedDeployment,
                    ],
                    ['MongoDB'],
                    [
                        'Relational joins and cross-document constraints are not the primary model.',
                        'Schema flexibility moves more validation and consistency responsibility into application design.',
                    ],
                ),
                new DatabaseFamilyDefinition(
                    'key-value',
                    'Key-Value',
                    [
                        DatabaseCapability::HorizontalScale,
                        DatabaseCapability::ReadIntensive,
                        DatabaseCapability::WriteIntensive,
                        DatabaseCapability::LowLatency,
                        DatabaseCapability::HighThroughput,
                        DatabaseCapability::Ttl,
                        DatabaseCapability::DistributedDeployment,
                        DatabaseCapability::ManagedDeployment,
                    ],
                    ['Redis', 'Valkey'],
                    [
                        'Complex joins and ad-hoc relational querying are not the primary model.',
                        'Access patterns normally require deliberate key design.',
                    ],
                ),
                new DatabaseFamilyDefinition(
                    'wide-column',
                    'Wide-Column',
                    [
                        DatabaseCapability::HorizontalScale,
                        DatabaseCapability::WriteIntensive,
                        DatabaseCapability::ReadIntensive,
                        DatabaseCapability::HighThroughput,
                        DatabaseCapability::DistributedDeployment,
                        DatabaseCapability::ManagedDeployment,
                    ],
                    ['Cassandra', 'ScyllaDB'],
                    [
                        'Schemas and query patterns are commonly designed around known access paths.',
                        'Relational joins and multi-row ACID workflows are not the primary strength.',
                    ],
                ),
                new DatabaseFamilyDefinition(
                    'graph',
                    'Graph',
                    [
                        DatabaseCapability::GraphTraversal,
                        DatabaseCapability::SchemaFlexibility,
                        DatabaseCapability::ReadIntensive,
                        DatabaseCapability::ManagedDeployment,
                    ],
                    ['Neo4j'],
                    [
                        'Graph traversal benefits do not automatically help ordinary tabular workloads.',
                        'Horizontal distribution can be more complex than simpler key-oriented systems.',
                    ],
                ),
                new DatabaseFamilyDefinition(
                    'time-series',
                    'Time-Series',
                    [
                        DatabaseCapability::TimeSeriesRetention,
                        DatabaseCapability::WriteIntensive,
                        DatabaseCapability::AnalyticalScans,
                        DatabaseCapability::Ttl,
                        DatabaseCapability::HighThroughput,
                        DatabaseCapability::ManagedDeployment,
                    ],
                    ['InfluxDB', 'TimescaleDB'],
                    [
                        'Time-oriented storage can be a poor fit for unrelated transactional domains.',
                        'Retention and downsampling policy needs explicit operational design.',
                    ],
                ),
                new DatabaseFamilyDefinition(
                    'vector',
                    'Vector',
                    [
                        DatabaseCapability::VectorSimilarity,
                        DatabaseCapability::ReadIntensive,
                        DatabaseCapability::HighThroughput,
                        DatabaseCapability::ManagedDeployment,
                    ],
                    ['Milvus', 'PostgreSQL + pgvector', 'Qdrant'],
                    [
                        'Similarity search does not replace transactional or domain modelling.',
                        'Index choices affect recall, latency, memory use and update cost.',
                    ],
                ),
                new DatabaseFamilyDefinition(
                    'search-index',
                    'Search / Index',
                    [
                        DatabaseCapability::FullTextSearch,
                        DatabaseCapability::ReadIntensive,
                        DatabaseCapability::HighThroughput,
                        DatabaseCapability::SchemaFlexibility,
                        DatabaseCapability::AnalyticalScans,
                        DatabaseCapability::ManagedDeployment,
                    ],
                    ['Elasticsearch', 'OpenSearch'],
                    [
                        'Search indexes are commonly derived rather than authoritative stores.',
                        'Indexing introduces synchronization and eventual-visibility concerns.',
                    ],
                ),
                new DatabaseFamilyDefinition(
                    'in-memory',
                    'In-Memory',
                    [
                        DatabaseCapability::LowLatency,
                        DatabaseCapability::HighThroughput,
                        DatabaseCapability::ReadIntensive,
                        DatabaseCapability::WriteIntensive,
                        DatabaseCapability::Ttl,
                    ],
                    ['Redis', 'Valkey'],
                    [
                        'Memory cost may be substantially higher than disk-oriented storage.',
                        'Durability semantics require deliberate configuration or architecture.',
                    ],
                ),
                new DatabaseFamilyDefinition(
                    'ledger-immutable',
                    'Ledger / Immutable',
                    [
                        DatabaseCapability::ImmutableHistory,
                        DatabaseCapability::StrongConsistency,
                        DatabaseCapability::AcidTransactions,
                    ],
                    [],
                    [
                        'Append-only and immutable models constrain ordinary update workflows.',
                        'Specialized integrity and audit semantics introduce modelling and operational complexity.',
                    ],
                ),
                new DatabaseFamilyDefinition(
                    'analytical-warehouse',
                    'Analytical Warehouse',
                    [
                        DatabaseCapability::AnalyticalScans,
                        DatabaseCapability::ReadIntensive,
                        DatabaseCapability::HighThroughput,
                        DatabaseCapability::ManagedDeployment,
                    ],
                    [],
                    [
                        'Analytical systems are normally not the primary OLTP transaction store.',
                        'Ingestion and freshness architecture must be designed explicitly.',
                    ],
                ),
                new DatabaseFamilyDefinition(
                    'multi-model',
                    'Multi-Model',
                    [
                        DatabaseCapability::SchemaFlexibility,
                        DatabaseCapability::ReadIntensive,
                        DatabaseCapability::ManagedDeployment,
                    ],
                    [],
                    [
                        'Multiple supported models can introduce product-specific semantics and complexity.',
                        'Broad feature coverage does not imply best-in-class support for every model.',
                    ],
                ),
            ],
        );
    }
}
