<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Integration;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteMatch;
use Evolve\I18n\LocalizationPolicy;
use Evolve\Insight\Access\DiagnosticAccessOperation;
use Evolve\Insight\Access\DiagnosticAccessPolicy;
use Evolve\Insight\Capture\DeterministicDiagnosticSampler;
use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticCaptureFilter;
use Evolve\Insight\Capture\DiagnosticCapturePolicy;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\Dashboard\DashboardExposure;
use Evolve\Insight\Dashboard\DashboardRoutes;
use Evolve\Insight\DiagnosticPipeline;
use Evolve\Insight\Infrastructure\ExecutionCorrelation;
use Evolve\Insight\Query\DiagnosticBatchQuery;
use Evolve\Insight\Query\DiagnosticQueryService;
use Evolve\Insight\Storage\DiagnosticBatchSnapshotCodec;
use Evolve\Insight\Storage\SqliteDiagnosticBatchStore;
use Evolve\Insight\Watcher\ExecutionLifecycleDiagnosticWatcher;
use Evolve\Insight\Watcher\ObservationDiagnosticWatcher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

final class InsightProductAcceptanceTest extends TestCase
{
    private static string $body = '';

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
    }

    public function testCapturePolicyBoundsPersistedSqliteQueryAndNativeDashboard(): void
    {
        $business = new \PDO('sqlite::memory:');
        $business->exec('CREATE TABLE business_records (id INTEGER PRIMARY KEY)');
        $diagnostics = new \PDO('sqlite::memory:');
        $store = new SqliteDiagnosticBatchStore($diagnostics, 3);
        $policy = new DiagnosticCapturePolicy(
            filter: new DiagnosticCaptureFilter(disabledNames: ['filtered']),
            sampler: new DeterministicDiagnosticSampler(100),
        );
        $pipeline = DiagnosticPipeline::storing($store, 10, 2, $policy, observationWatchers: [new ProductStartedWatcher()]);
        $services = new ServiceRegistry();
        $services->freeze();
        $orchestrator = new ExecutionOrchestrator($services, $pipeline);
        $outcome = $orchestrator->execute(ExecutionKind::WorkerTask, function ($context) use ($pipeline): void {
            $id = $context->identifier()->value();
            $pipeline->capture(new DiagnosticEntry($id, 'product', 'redacted', [
                new DiagnosticAttribute('accessToken', DiagnosticDataClassification::PublicOperationalMetadata, 'raw-token-secret'),
                new DiagnosticAttribute('detail', DiagnosticDataClassification::PublicOperationalMetadata, 'second-visible'),
            ]));
            $pipeline->capture($this->entry($id, 'classified-out', 'secret', 'classified-secret', DiagnosticDataClassification::SecretData));
            $pipeline->capture($this->entry($id, 'filtered', 'detail', 'filtered-secret'));
            $pipeline->capture($this->entry($id, 'overflow', 'detail', 'overflow-visible'));
        });
        self::assertTrue($outcome->primarySucceeded());

        $id = $outcome->identifier()->value();
        $rawPayload = $diagnostics->query('SELECT snapshot_payload FROM insight_diagnostic_batches')->fetchColumn();
        self::assertIsString($rawPayload);
        foreach (['raw-token-secret', 'classified-secret', 'filtered-secret', 'overflow-visible'] as $secret) {
            self::assertStringNotContainsString($secret, $rawPayload);
        }
        self::assertStringContainsString('[REDACTED]', $rawPayload);
        self::assertFalse($business->query("SELECT name FROM sqlite_master WHERE name = 'insight_diagnostic_batches'")->fetchColumn());
        self::assertFalse($diagnostics->query("SELECT name FROM sqlite_master WHERE name = 'business_records'")->fetchColumn());

        $access = new ProductAccessPolicy();
        $queries = new DiagnosticQueryService($store, $access);
        $page = $queries->query(new DiagnosticBatchQuery(10));
        self::assertSame([$id], array_map(static fn($item): string => $item->executionIdentifier(), $page->items()));
        $snapshot = $queries->find($id);
        self::assertNotNull($snapshot);
        self::assertSame(['first', 'redacted'], array_map(static fn($entry): string => $entry->name(), $snapshot->diagnosticEntries()));
        self::assertSame(1, $snapshot->droppedDiagnosticEntryCount());
        self::assertSame('[REDACTED]', $snapshot->diagnosticEntries()[1]->attributes()[0]->value());
        self::assertSame(1, $page->items()[0]->droppedDiagnosticEntryCount());

        $routes = DashboardRoutes::native(
            $queries,
            $this->responseFactory(),
            (new LocalizationPolicy(['en'], 'en', [], 'UTC'))->context(),
            DashboardExposure::localDevelopment(),
        )->all();
        [$listResponse, $listHtml] = $this->dispatch($routes[0]);
        [$detailResponse, $detailHtml] = $this->dispatch($routes[1], $id);
        self::assertSame(200, $listResponse->getStatusCode());
        self::assertSame(200, $detailResponse->getStatusCode());
        self::assertStringContainsString('Dropped entries</dt><dd>1', $detailHtml);
        self::assertStringContainsString('first-visible', $detailHtml);
        self::assertStringContainsString('[REDACTED]', $detailHtml);
        foreach (['raw-token-secret', 'classified-secret', 'filtered-secret', 'overflow-visible'] as $secret) {
            self::assertStringNotContainsString($secret, $listHtml);
            self::assertStringNotContainsString($secret, $detailHtml);
        }

        $access->allow = false;
        self::assertSame(403, $this->dispatch($routes[0])[0]->getStatusCode());
        self::assertSame(403, $this->dispatch($routes[1], $id)[0]->getStatusCode());
        self::assertSame([
            [DiagnosticAccessOperation::List, null],
            [DiagnosticAccessOperation::Detail, $id],
            [DiagnosticAccessOperation::List, null],
            [DiagnosticAccessOperation::Detail, $id],
            [DiagnosticAccessOperation::List, null],
            [DiagnosticAccessOperation::Detail, $id],
        ], $access->calls);
    }

    public function testSequentialWorkerExecutionsDetachCorrelationAndLiveContexts(): void
    {
        $store = new SqliteDiagnosticBatchStore(new \PDO('sqlite::memory:'), 3);
        $pipeline = DiagnosticPipeline::storing(
            $store,
            10,
            5,
            observationWatchers: [new ExecutionLifecycleDiagnosticWatcher()],
        );
        $correlation = new ExecutionCorrelation();
        $services = new ServiceRegistry();
        $services->freeze();
        $orchestrator = new ExecutionOrchestrator($services, $pipeline, [$correlation]);
        $firstContext = null;
        $first = $orchestrator->execute(ExecutionKind::WorkerTask, function ($context) use ($pipeline, $correlation, &$firstContext): void {
            $firstContext = \WeakReference::create($context);
            self::assertSame($context->identifier()->value(), $correlation->identifier());
            $pipeline->capture($this->entry($context->identifier()->value(), 'first-only', 'detail', 'first-run'));
            throw new \RuntimeException('first execution failed');
        });
        self::assertFalse($first->primarySucceeded());
        self::assertNull($correlation->identifier());
        gc_collect_cycles();
        self::assertNull($firstContext->get());

        $secondContext = null;
        $second = $orchestrator->execute(ExecutionKind::WorkerTask, function ($context) use ($pipeline, $correlation, &$secondContext): void {
            $secondContext = \WeakReference::create($context);
            self::assertSame($context->identifier()->value(), $correlation->identifier());
            $pipeline->capture($this->entry($context->identifier()->value(), 'second-only', 'detail', 'second-run'));
        });
        self::assertTrue($second->primarySucceeded());
        self::assertNotSame($first->identifier()->value(), $second->identifier()->value());
        self::assertNull($correlation->identifier());
        gc_collect_cycles();
        self::assertNull($secondContext->get());

        $firstSnapshot = $store->find($first->identifier()->value());
        $secondSnapshot = $store->find($second->identifier()->value());
        self::assertNotNull($firstSnapshot);
        self::assertNotNull($secondSnapshot);
        self::assertSame(['first-only', 'handler-failed'], array_map(static fn($entry): string => $entry->name(), $firstSnapshot->diagnosticEntries()));
        self::assertSame(['second-only'], array_map(static fn($entry): string => $entry->name(), $secondSnapshot->diagnosticEntries()));
        self::assertSame('first-run', $firstSnapshot->diagnosticEntries()[0]->attributes()[0]->value());
        self::assertSame('second-run', $secondSnapshot->diagnosticEntries()[0]->attributes()[0]->value());
    }

    public function testUncomposedInsightCreatesNoStorageAndZeroSamplingDoesNotCountCapacityDrops(): void
    {
        $business = new \PDO('sqlite::memory:');
        $services = new ServiceRegistry();
        $services->freeze();
        $plain = new ExecutionOrchestrator($services);
        self::assertTrue($plain->execute(ExecutionKind::WorkerTask, static function (): void {})->primarySucceeded());
        self::assertFalse($business->query("SELECT name FROM sqlite_master WHERE name = 'insight_diagnostic_batches'")->fetchColumn());

        $diagnostics = new \PDO('sqlite::memory:');
        $store = new SqliteDiagnosticBatchStore($diagnostics, 2);
        $pipeline = DiagnosticPipeline::storing(
            $store,
            10,
            1,
            new DiagnosticCapturePolicy(sampler: new DeterministicDiagnosticSampler(0)),
        );
        $sampled = new ExecutionOrchestrator($services, $pipeline);
        $outcome = $sampled->execute(ExecutionKind::WorkerTask, function ($context) use ($pipeline): void {
            $pipeline->capture($this->entry($context->identifier()->value(), 'sampled-out', 'detail', 'sampled-secret'));
            $pipeline->capture($this->entry($context->identifier()->value(), 'sampled-out-again', 'detail', 'sampled-secret-2'));
        });
        $snapshot = $store->find($outcome->identifier()->value());
        self::assertNotNull($snapshot);
        self::assertSame([], $snapshot->diagnosticEntries());
        self::assertSame(0, $snapshot->droppedDiagnosticEntryCount());

        $rawPayload = $diagnostics->query('SELECT snapshot_payload FROM insight_diagnostic_batches')->fetchColumn();
        self::assertIsString($rawPayload);
        $queries = new DiagnosticQueryService($store, new ProductAccessPolicy());
        $queried = $queries->find($outcome->identifier()->value());
        self::assertNotNull($queried);
        self::assertSame([], $queried->diagnosticEntries());
        self::assertSame(0, $queried->droppedDiagnosticEntryCount());
        $page = $queries->query(new DiagnosticBatchQuery(10));
        self::assertCount(1, $page->items());
        self::assertSame(0, $page->items()[0]->droppedDiagnosticEntryCount());

        $routes = DashboardRoutes::native(
            $queries,
            $this->responseFactory(),
            (new LocalizationPolicy(['en'], 'en', [], 'UTC'))->context(),
            DashboardExposure::localDevelopment(),
        )->all();
        [$listResponse, $listHtml] = $this->dispatch($routes[0]);
        [$detailResponse, $detailHtml] = $this->dispatch($routes[1], $outcome->identifier()->value());
        self::assertSame(200, $listResponse->getStatusCode());
        self::assertSame(200, $detailResponse->getStatusCode());
        self::assertStringContainsString('Dropped entries</dt><dd>0', $detailHtml);
        $queriedPayload = (new DiagnosticBatchSnapshotCodec())->encode($queried);
        foreach (['sampled-secret', 'sampled-secret-2'] as $value) {
            self::assertStringNotContainsString($value, $rawPayload);
            self::assertStringNotContainsString($value, $queriedPayload);
            self::assertStringNotContainsString($value, $listHtml);
            self::assertStringNotContainsString($value, $detailHtml);
        }
        self::assertFalse($business->query("SELECT name FROM sqlite_master WHERE name = 'insight_diagnostic_batches'")->fetchColumn());
    }

    private function entry(
        string $id,
        string $name,
        string $attributeName,
        string $value,
        DiagnosticDataClassification $classification = DiagnosticDataClassification::PublicOperationalMetadata,
    ): DiagnosticEntry {
        return new DiagnosticEntry($id, 'product', $name, [
            new DiagnosticAttribute($attributeName, $classification, $value),
        ]);
    }

    private function responseFactory(): ResponseFactoryInterface
    {
        $factory = $this->createStub(ResponseFactoryInterface::class);
        $factory->method('createResponse')->willReturnCallback(function (int $code = 200): ResponseInterface {
            $headers = [];
            $stream = $this->createStub(StreamInterface::class);
            $stream->method('write')->willReturnCallback(static function (string $body): int {
                self::$body = $body;
                return strlen($body);
            });
            $response = $this->createStub(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn($code);
            $response->method('getBody')->willReturn($stream);
            $response->method('withHeader')->willReturnCallback(static function (string $name, $value) use ($response, &$headers): ResponseInterface {
                $headers[strtolower($name)] = $value;
                return $response;
            });
            $response->method('getHeaderLine')->willReturnCallback(static function (string $name) use (&$headers): string {
                return (string) ($headers[strtolower($name)] ?? '');
            });
            return $response;
        });
        return $factory;
    }

    /** @return array{ResponseInterface, string} */
    private function dispatch(Route $route, ?string $execution = null): array
    {
        self::$body = '';
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturn(new RouteMatch($route, $execution === null ? [] : ['execution' => $execution]));
        $response = $route->handler()->handle($request);
        return [$response, self::$body];
    }
}

final class ProductAccessPolicy implements DiagnosticAccessPolicy
{
    public bool $allow = true;

    /** @var list<array{DiagnosticAccessOperation, ?string}> */
    public array $calls = [];

    public function allows(DiagnosticAccessOperation $operation, ?string $executionIdentifier = null): bool
    {
        $this->calls[] = [$operation, $executionIdentifier];
        return $this->allow;
    }
}

final class ProductStartedWatcher implements ObservationDiagnosticWatcher
{
    public function watch(Observation $observation): array
    {
        if ($observation->type() !== ObservationType::ExecutionStarted) {
            return [];
        }

        return [new DiagnosticEntry($observation->identifier()->value(), 'product', 'first', [
            new DiagnosticAttribute('detail', DiagnosticDataClassification::PublicOperationalMetadata, 'first-visible'),
        ])];
    }
}
