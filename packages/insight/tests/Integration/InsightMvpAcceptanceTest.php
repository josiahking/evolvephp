<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Integration;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Insight\Access\DiagnosticAccessOperation;
use Evolve\Insight\Access\DiagnosticAccessPolicy;
use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticCapturePolicy;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\DiagnosticPipeline;
use Evolve\Insight\Infrastructure\DatabaseDiagnosticDecorator;
use Evolve\Insight\Infrastructure\DatabaseDiagnosticPolicy;
use Evolve\Insight\Infrastructure\DiagnosticRecorder;
use Evolve\Insight\Infrastructure\ExecutionCorrelation;
use Evolve\Insight\Query\DiagnosticBatchQuery;
use Evolve\Insight\Query\DiagnosticQueryService;
use Evolve\Insight\Storage\InMemoryDiagnosticBatchStore;
use Evolve\Insight\Watcher\ExecutionLifecycleDiagnosticWatcher;
use Evolve\Insight\Watcher\ObservationDiagnosticWatcher;
use PHPUnit\Framework\TestCase;

final class InsightMvpAcceptanceTest extends TestCase
{
    public function testInfrastructureEntriesShareNormalBatchesAndResetBetweenExecutions(): void
    {
        $store = new InMemoryDiagnosticBatchStore(5);
        $pipeline = DiagnosticPipeline::storing($store, 10, 10);
        $correlation = new ExecutionCorrelation();
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->expects(self::exactly(3))->method('execute')->willReturn(1);
        $database = new DatabaseDiagnosticDecorator($connection, new DiagnosticRecorder($pipeline, $correlation), new DatabaseDiagnosticPolicy(captureSql: true));
        $statement = new DatabaseStatement('SELECT private_value', ['bound-secret']);
        self::assertSame(1, $database->execute($statement));
        $services = new ServiceRegistry();
        $services->freeze();
        $orchestrator = new ExecutionOrchestrator($services, $pipeline, [$correlation]);
        $first = $orchestrator->execute(ExecutionKind::WorkerTask, static function () use ($database, $statement): void {
            $database->execute($statement);
        });
        $second = $orchestrator->execute(ExecutionKind::WorkerTask, static function () use ($database, $statement): void {
            $database->execute($statement);
        });
        self::assertTrue($first->primarySucceeded());
        self::assertTrue($second->primarySucceeded());
        self::assertNotSame($first->identifier()->value(), $second->identifier()->value());
        self::assertNull($correlation->identifier());
        foreach ([$first, $second] as $outcome) {
            $snapshot = $store->find($outcome->identifier()->value());
            self::assertNotNull($snapshot);
            self::assertCount(1, $snapshot->diagnosticEntries());
            $values = [];
            foreach ($snapshot->diagnosticEntries()[0]->attributes() as $attribute) {
                $values[$attribute->name()] = $attribute->value();
            }
            self::assertSame(1, $values['repeat_occurrence']);
            self::assertArrayNotHasKey('sql', $values);
            self::assertNotContains('bound-secret', $values);
        }
    }

    public function testSqlInspectionRequiresBothDatabaseAndCapturePolicyOptIn(): void
    {
        $store = new InMemoryDiagnosticBatchStore(2);
        $policy = new DiagnosticCapturePolicy(acceptedClassifications: [
            DiagnosticDataClassification::PublicOperationalMetadata,
            DiagnosticDataClassification::InternalOperationalMetadata,
            DiagnosticDataClassification::BusinessSensitivePayload,
        ]);
        $pipeline = DiagnosticPipeline::storing($store, 10, 10, $policy);
        $correlation = new ExecutionCorrelation();
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->expects(self::once())->method('execute')->willReturn(1);
        $database = new DatabaseDiagnosticDecorator($connection, new DiagnosticRecorder($pipeline, $correlation), new DatabaseDiagnosticPolicy(captureSql: true, maximumSqlLength: 8));
        $services = new ServiceRegistry();
        $services->freeze();
        $orchestrator = new ExecutionOrchestrator($services, $pipeline, [$correlation]);
        $outcome = $orchestrator->execute(ExecutionKind::WorkerTask, static function () use ($database): void {
            $database->execute(new DatabaseStatement('SELECT private_column', ['bound-secret']));
        });
        $snapshot = $store->find($outcome->identifier()->value());
        self::assertNotNull($snapshot);
        $values = [];
        foreach ($snapshot->diagnosticEntries()[0]->attributes() as $attribute) {
            $values[$attribute->name()] = $attribute->value();
        }
        self::assertSame('SELECT p', $values['sql']);
        self::assertTrue($values['sql_truncated']);
        self::assertNotContains('bound-secret', $values);
    }

    public function testInsightMvpCapturesPersistsQueriesAndReadsThroughExplicitAccessPolicy(): void
    {
        $store = new InMemoryDiagnosticBatchStore(5);
        $pipeline = DiagnosticPipeline::storing(
            $store,
            10,
            10,
            observationWatchers: [
                new ExecutionLifecycleDiagnosticWatcher(),
                new SensitiveOperationalWatcher(),
            ],
        );
        $identifier = ExecutionIdentifier::generate();
        $rawSensitiveValue = SensitiveOperationalWatcher::RAW_VALUE;

        $pipeline->observe(new Observation(ObservationType::ExecutionStarted, $identifier, ExecutionKind::HttpRequest));
        $pipeline->observe(new Observation(
            ObservationType::HandlerCompleted,
            $identifier,
            ExecutionKind::HttpRequest,
            ObservationOutcome::Failed,
            \RuntimeException::class,
        ));
        $pipeline->observe(new Observation(ObservationType::ExecutionCompleted, $identifier, ExecutionKind::HttpRequest));

        $accessPolicy = new AllowingDiagnosticAccessPolicy();
        $queryService = new DiagnosticQueryService($store, $accessPolicy);
        $page = $queryService->query(new DiagnosticBatchQuery(
            10,
            executionKind: 'http-request',
            diagnosticCategory: 'evolve.execution',
            diagnosticName: 'handler-failed',
        ));
        $detail = $queryService->find($identifier->value());

        self::assertCount(1, $page->items());
        self::assertSame($identifier->value(), $page->items()[0]->executionIdentifier());
        self::assertNull($page->nextCursor());
        self::assertNotNull($detail);
        self::assertSame([
            [DiagnosticAccessOperation::List, null],
            [DiagnosticAccessOperation::Detail, $identifier->value()],
        ], $accessPolicy->calls);

        $entries = $detail->diagnosticEntries();
        self::assertTrue($this->containsEntry($entries, 'evolve.execution', 'handler-failed'));
        self::assertTrue($this->containsEntry($entries, 'test.sensitive', 'redaction-probe'));

        $encodedDetail = json_encode($this->entryAttributeValues($entries), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('[REDACTED]', $encodedDetail);
        self::assertStringNotContainsString($rawSensitiveValue, $encodedDetail);
    }

    /**
     * @param array<array-key, mixed> $entries
     */
    private function containsEntry(array $entries, string $category, string $name): bool
    {
        foreach ($entries as $entry) {
            if ($entry->category() === $category && $entry->name() === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $entries
     *
     * @return list<array<string, string|int|float|bool|null>>
     */
    private function entryAttributeValues(array $entries): array
    {
        $values = [];

        foreach ($entries as $entry) {
            $entryValues = [];

            foreach ($entry->attributes() as $attribute) {
                $entryValues[$attribute->name()] = $attribute->value();
            }

            $values[] = $entryValues;
        }

        return $values;
    }
}

final class SensitiveOperationalWatcher implements ObservationDiagnosticWatcher
{
    public const string RAW_VALUE = 'raw-sensitive-token-value';

    public function watch(Observation $observation): array
    {
        if ($observation->type() !== ObservationType::HandlerCompleted) {
            return [];
        }

        return [new DiagnosticEntry(
            $observation->identifier()->value(),
            'test.sensitive',
            'redaction-probe',
            [new DiagnosticAttribute(
                'accessToken',
                DiagnosticDataClassification::PublicOperationalMetadata,
                self::RAW_VALUE,
            )],
        )];
    }
}

final class AllowingDiagnosticAccessPolicy implements DiagnosticAccessPolicy
{
    /**
     * @var list<array{DiagnosticAccessOperation, ?string}>
     */
    public array $calls = [];

    public function allows(DiagnosticAccessOperation $operation, ?string $executionIdentifier = null): bool
    {
        $this->calls[] = [$operation, $executionIdentifier];

        return true;
    }
}
