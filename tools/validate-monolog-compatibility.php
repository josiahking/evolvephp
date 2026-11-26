<?php

declare(strict_types=1);

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionResetFailed;
use Evolve\Core\Exception\ExecutionScopeUnavailable;
use Evolve\Core\Exception\ExecutionStartFailed;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Core\Execution\ProcessReuseDecision;
use Evolve\Observe\ExecutionTraceInstrumentation;
use Evolve\Observe\Logging\ExecutionLogCorrelationInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use Psr\Log\LoggerInterface;

$autoload = getenv('EVOLVE_MONOLOG_CONSUMER_AUTOLOAD');
if (!is_string($autoload) || !is_file($autoload)) {
    fwrite(STDERR, "Set EVOLVE_MONOLOG_CONSUMER_AUTOLOAD to an isolated consumer autoload.php.\n");
    exit(2);
}
require $autoload;
require __DIR__ . '/fixtures/monolog-psr3-consumer.php';

function checkMonolog(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function monologRecord(MonologTestHandler $handler): LogRecord
{
    $records = $handler->getRecords();
    checkMonolog(count($records) === 1, 'Expected exactly one handler record.');
    return $records[0];
}

function expectMonologException(callable $operation, string $category): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        checkMonolog($error instanceof $category, 'Unexpected exception: ' . $error::class);
        return $error;
    }
    throw new RuntimeException('Expected exception was not raised.');
}

function validateMonologBase(): void
{
    checkMonolog(!class_exists(ExecutionLogCorrelationInstrumentation::class), 'Base consumer unexpectedly installed Observe.');
    $empty = new ServiceRegistry();
    checkMonolog(!$empty->freeze()->has(LoggerInterface::class), 'Logger was registered automatically.');

    $services = new ServiceRegistry();
    registerMonologLogger($services);
    $root = $services->freeze();
    checkMonolog($root->has(LoggerInterface::class), 'Logger binding is absent.');
    expectMonologException(static fn() => $root->get(LoggerInterface::class), ExecutionScopeUnavailable::class);

    $handlers = [];
    $processors = [];
    $loggers = [];
    $orchestrator = new ExecutionOrchestrator($services);
    foreach ([ExecutionKind::HttpRequest, ExecutionKind::QueueMessage] as $kind) {
        $outcome = $orchestrator->execute($kind, static function (ExecutionContext $context, ExecutionScope $scope) use (&$handlers, &$processors, &$loggers): void {
            $logger = $scope->get(LoggerInterface::class);
            checkMonolog($logger === $scope->get(LoggerInterface::class), 'Logger changed within one execution.');
            $loggers[] = $logger;
            $handler = $logger->getHandlers()[0];
            $processor = $logger->getProcessors()[0];
            checkMonolog($handler instanceof MonologTestHandler, 'Unexpected handler.');
            checkMonolog($processor instanceof MonologCorrelationProcessor, 'Unexpected processor.');
            checkMonolog($handler->getRecords() === [], 'Fresh execution inherited handler records.');
            $handlers[] = $handler;
            $processors[] = $processor;
            (new MonologGreetingService($logger))->greet('reader');
            $record = monologRecord($handler);
            checkMonolog($record->message === 'greeting sent', 'Message was changed.');
            checkMonolog($record->level === Level::Info, 'Severity was changed.');
            checkMonolog($record->context === ['recipient' => 'reader'], 'Caller context was changed.');
            checkMonolog($record->extra === [], 'Absent provider inserted correlation.');
            checkMonolog($processor->processedCount() === 1, 'Processor did not run.');
        });
        checkMonolog($outcome->primarySucceeded() && $outcome->isReusable(), 'Normal execution failed.');
    }
    checkMonolog($loggers[0] !== $loggers[1], 'Logger leaked between executions.');
    foreach ($handlers as $handler) {
        checkMonolog($handler->resetCount() === 1 && $handler->getRecords() === [], 'Handler reset or clearing failed.');
    }
    foreach ($processors as $processor) {
        checkMonolog($processor->resetCount() === 1 && $processor->processedCount() === 0, 'Processor reset failed.');
    }

    $failedHandler = null;
    $outcome = $orchestrator->execute(ExecutionKind::QueueMessage, static function (ExecutionContext $context, ExecutionScope $scope) use (&$failedHandler): void {
        $logger = $scope->get(LoggerInterface::class);
        $failedHandler = $logger->getHandlers()[0];
        $logger->warning('before exception');
        throw new LogicException('application failure');
    });
    checkMonolog($outcome->primaryFailed() && $outcome->primaryThrowable() instanceof LogicException, 'Application exception was lost.');
    checkMonolog($outcome->isReusable() && $failedHandler instanceof MonologTestHandler, 'Application exception affected cleanup.');
    checkMonolog($failedHandler->getRecords() === [] && $failedHandler->resetCount() === 1, 'Exception path did not reset handler.');

    $failing = new ServiceRegistry();
    registerMonologLogger($failing, handlerFactory: static fn(): MonologTestHandler => new MonologTestHandler(failReset: true));
    $failing->freeze();
    $failingOrchestrator = new ExecutionOrchestrator($failing);
    $failure = $failingOrchestrator->execute(ExecutionKind::QueueMessage, static function (ExecutionContext $context, ExecutionScope $scope): void {
        $scope->get(LoggerInterface::class)->info('reset will fail');
    });
    checkMonolog($failure->primarySucceeded() && $failure->cleanupFailed(), 'Reset failure was not reported.');
    checkMonolog($failure->cleanupThrowable() instanceof ExecutionResetFailed, 'Wrong cleanup failure category.');
    checkMonolog($failure->reuseDecision() === ProcessReuseDecision::QuarantineRequired, 'Reset failure did not quarantine worker.');
    expectMonologException(static fn() => $failingOrchestrator->execute(ExecutionKind::QueueMessage, static fn() => null), ExecutionStartFailed::class);
}

function validateMonologCorrelation(): void
{
    checkMonolog(class_exists(ExecutionLogCorrelationInstrumentation::class), 'Observe is missing from correlation consumer.');
    $resource = ResourceInfo::create(Attributes::create([ServiceAttributes::SERVICE_NAME => 'monolog-consumer']));
    $exporter = new InMemoryExporter();
    $tracerProvider = new TracerProvider(new SimpleSpanProcessor($exporter), resource: $resource);
    $composition = new OpenTelemetryComposition(enabled: true, tracerProvider: $tracerProvider, resource: $resource);
    $tracing = new ExecutionTraceInstrumentation($composition);
    $correlation = new ExecutionLogCorrelationInstrumentation($composition);

    $services = new ServiceRegistry();
    registerMonologLogger($services, static fn(): array => $correlation->current()->structuredFields());
    $services->freeze();
    $orchestrator = new ExecutionOrchestrator($services, [$tracing], [$correlation, $tracing]);
    $ids = [];
    $handlers = [];
    foreach ([ExecutionKind::HttpRequest, ExecutionKind::QueueMessage] as $kind) {
        $outcome = $orchestrator->execute($kind, static function (ExecutionContext $context, ExecutionScope $scope) use (&$ids, &$handlers): void {
            $logger = $scope->get(LoggerInterface::class);
            $handler = $logger->getHandlers()[0];
            checkMonolog($handler instanceof MonologTestHandler, 'Unexpected correlation handler.');
            checkMonolog($handler->getRecords() === [], 'Fresh correlation execution inherited handler records.');
            $handlers[] = $handler;
            $spanContext = Span::getCurrent()->getContext();
            checkMonolog($spanContext->isValid(), 'No active tracing span.');
            $logger->info('correlated', ['caller' => 'preserved', 'trace_id' => 'caller context']);
            $record = monologRecord($handler);
            checkMonolog($record->context === ['caller' => 'preserved', 'trace_id' => 'caller context'], 'Caller context was changed.');
            checkMonolog($record->extra['trace_id'] === strtolower($spanContext->getTraceId()), 'Wrong trace identifier.');
            checkMonolog($record->extra['span_id'] === strtolower($spanContext->getSpanId()), 'Wrong span identifier.');
            checkMonolog($record->extra['trace_flags'] === sprintf('%02x', $spanContext->getTraceFlags() & 0xff), 'Wrong trace flags.');
            checkMonolog($record->extra['evolve.execution.id'] === $context->identifier()->value(), 'Wrong execution identifier.');
            checkMonolog($record->extra['evolve.execution.kind'] === $context->kind()->value, 'Wrong execution kind.');
            $ids[] = $record->extra['evolve.execution.id'];
            $processor = $logger->getProcessors()[0];
            checkMonolog($processor instanceof MonologCorrelationProcessor, 'Unexpected correlation processor.');
            $spoofed = new LogRecord(new DateTimeImmutable(), 'application', Level::Info, 'spoofed', [], [
                'trace_id' => 'spoof', 'span_id' => 'spoof', 'trace_flags' => 'spoof',
                'evolve.execution.id' => 'spoof', 'evolve.execution.kind' => 'spoof', 'other' => 'kept',
            ]);
            $cleaned = $processor($spoofed);
            checkMonolog($cleaned->extra['other'] === 'kept', 'Unrelated extra field was removed.');
            checkMonolog($cleaned->extra['trace_id'] === strtolower($spanContext->getTraceId()), 'Spoofed trace field survived.');
            checkMonolog($cleaned->extra['span_id'] === strtolower($spanContext->getSpanId()), 'Spoofed span field survived.');
            checkMonolog($cleaned->extra['evolve.execution.id'] === $context->identifier()->value(), 'Spoofed execution field survived.');
        });
        checkMonolog($outcome->primarySucceeded() && $outcome->isReusable(), 'Correlated execution failed.');
    }
    checkMonolog($ids[0] !== $ids[1], 'Execution identifier leaked.');
    foreach ($handlers as $handler) {
        checkMonolog($handler->getRecords() === [] && $handler->resetCount() === 1, 'Correlation handler retained a prior record.');
    }
    checkMonolog($correlation->current()->isEmpty(), 'Execution correlation remains attached outside execution.');
    $outside = new MonologTestHandler();
    $logger = new \Monolog\Logger('outside', [$outside], [new MonologCorrelationProcessor(static fn(): array => $correlation->current()->structuredFields())]);
    $logger->info('outside');
    checkMonolog(monologRecord($outside)->extra === [], 'Outside logging retained stale identifiers.');

    $disabled = new ExecutionLogCorrelationInstrumentation(OpenTelemetryComposition::disabled());
    $disabledProcessor = new MonologCorrelationProcessor(static fn(): array => $disabled->current()->structuredFields());
    $record = new LogRecord(new DateTimeImmutable(), 'test', Level::Info, 'disabled', [], ['trace_id' => 'spoof', 'other' => 'kept']);
    $processed = $disabledProcessor($record);
    checkMonolog($processed->extra === ['other' => 'kept'], 'Disabled provider retained spoofed fields or removed unrelated extra.');
    $absentProcessor = new MonologCorrelationProcessor(null);
    $absent = $absentProcessor(new LogRecord(new DateTimeImmutable(), 'test', Level::Info, 'absent', [], ['span_id' => 'spoof', 'other' => 'kept']));
    checkMonolog($absent->extra === ['other' => 'kept'], 'Absent provider retained spoofed fields.');
    checkMonolog($tracerProvider->forceFlush(), 'Trace provider flush failed.');
    checkMonolog($tracerProvider->shutdown(), 'Trace provider shutdown failed.');
}

try {
    $mode = $argv[1] ?? null;
    if ($mode === '--base') {
        validateMonologBase();
    } elseif ($mode === '--correlation') {
        validateMonologCorrelation();
    } else {
        fwrite(STDERR, "Use --base or --correlation.\n");
        exit(2);
    }
    echo "PASS isolated Monolog consumer {$mode}\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error::class . ': ' . $error->getMessage() . "\n");
    exit(1);
}
