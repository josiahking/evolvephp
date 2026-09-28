<?php

declare(strict_types=1);

namespace Evolve\DevTools\Database;

/**
 * @experimental
 */
enum DatabaseCapability: string
{
    case AcidTransactions = 'acid-transactions';
    case Joins = 'joins';
    case SchemaFlexibility = 'schema-flexibility';
    case StrongConsistency = 'strong-consistency';
    case EventualConsistency = 'eventual-consistency';
    case HorizontalScale = 'horizontal-scale';
    case WriteIntensive = 'write-intensive';
    case ReadIntensive = 'read-intensive';
    case LowLatency = 'low-latency';
    case HighThroughput = 'high-throughput';
    case FullTextSearch = 'full-text-search';
    case VectorSimilarity = 'vector-similarity';
    case GraphTraversal = 'graph-traversal';
    case TimeSeriesRetention = 'time-series-retention';
    case Geospatial = 'geospatial';
    case Ttl = 'ttl';
    case ChangeStreams = 'change-streams';
    case AnalyticalScans = 'analytical-scans';
    case EmbeddedOffline = 'embedded-offline';
    case DistributedDeployment = 'distributed-deployment';
    case ManagedDeployment = 'managed-deployment';
    case ImmutableHistory = 'immutable-history';
}
