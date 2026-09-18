<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Integration;

use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationOutcome;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Insight\Access\DiagnosticAccessOperation;
use Evolve\Insight\Access\DiagnosticAccessPolicy;
use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\DiagnosticPipeline;
use Evolve\Insight\Query\DiagnosticBatchQuery;
use Evolve\Insight\Query\DiagnosticQueryService;
use Evolve\Insight\Storage\InMemoryDiagnosticBatchStore;
use Evolve\Insight\Watcher\ExecutionLifecycleDiagnosticWatcher;
use Evolve\Insight\Watcher\ObservationDiagnosticWatcher;
use PHPUnit\Framework\TestCase;

final class InsightMvpAcceptanceTest extends TestCase
{
    public function testInsightMvpCapturesPersistsQueriesAndReadsThroughExplicitAccessPolicy(): void
    {
        $store = new InMemoryDiagnosticBatchStore(5);
        $pipeline = DiagnosticPipeline::storing(
            $store,
            10,
            10,
            observationWatchers: array(
                new ExecutionLifecycleDiagnosticWatcher(),
                new SensitiveOperationalWatcher(),
            ),
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
        self::assertSame(array(
            array(DiagnosticAccessOperation::List, null),
            array(DiagnosticAccessOperation::Detail, $identifier->value()),
        ), $accessPolicy->calls);

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
        $values = array();

        foreach ($entries as $entry) {
            $entryValues = array();

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
            return array();
        }

        return array(new DiagnosticEntry(
            $observation->identifier()->value(),
            'test.sensitive',
            'redaction-probe',
            array(new DiagnosticAttribute(
                'accessToken',
                DiagnosticDataClassification::PublicOperationalMetadata,
                self::RAW_VALUE,
            )),
        ));
    }
}

final class AllowingDiagnosticAccessPolicy implements DiagnosticAccessPolicy
{
    /**
     * @var list<array{DiagnosticAccessOperation, ?string}>
     */
    public array $calls = array();

    public function allows(DiagnosticAccessOperation $operation, ?string $executionIdentifier = null): bool
    {
        $this->calls[] = array($operation, $executionIdentifier);

        return true;
    }
}
