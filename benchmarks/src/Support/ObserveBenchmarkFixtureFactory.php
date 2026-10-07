<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Support;

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Observe\ExecutionMetricsInstrumentation;
use Evolve\Observe\ExecutionTraceInstrumentation;
use Evolve\Observe\Http\HttpServerMetricsInstrumentation;
use Evolve\Observe\Http\HttpServerTraceInstrumentation;
use Evolve\Observe\Logging\ExecutionLogCorrelationInstrumentation;
use Evolve\Observe\OpenTelemetryComposition;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ObserveBenchmarkFixtureFactory
{
    public function executionFixture(string $mode, ?TracerProviderInterface $tracerProvider = null): ObserveBenchmarkFixture
    {
        $resource = $this->resource('observe-benchmark-execution');
        $composition = $this->composition($mode, $resource, $tracerProvider);
        $activeDuringOperation = false;
        $operation = function () use (&$activeDuringOperation): string {
            $activeDuringOperation = Span::getCurrent()->getContext()->isValid();

            return 'ok';
        };
        $registry = new ServiceRegistry();
        $registry->freeze();

        if ($mode === 'bare') {
            $orchestrator = new ExecutionOrchestrator($registry);
        } else {
            $metrics = new ExecutionMetricsInstrumentation($composition);
            $trace = new ExecutionTraceInstrumentation($composition);
            $correlation = new ExecutionLogCorrelationInstrumentation($composition);
            $orchestrator = new ExecutionOrchestrator(
                $registry,
                [$metrics, $trace],
                [$correlation, $trace],
            );
        }

        return new ObserveBenchmarkFixture(
            function () use ($orchestrator, $operation): string {
                $outcome = $orchestrator->execute(ExecutionKind::HttpRequest, $operation);

                return $outcome->primaryResult();
            },
            function () use (&$activeDuringOperation): bool {
                return $activeDuringOperation;
            },
        );
    }

    public function httpFixture(string $mode, ?TracerProviderInterface $tracerProvider = null): ObserveBenchmarkFixture
    {
        $composition = $this->composition($mode, $this->resource('observe-benchmark-http'), $tracerProvider);
        $prepared = BenchmarkFixtureFactory::httpKernelFixture('static');
        $kernel = $prepared['kernel'];
        $request = $prepared['request'];
        $activeDuringOperation = false;

        if ($mode === 'bare') {
            $operation = function () use ($kernel, $request): ResponseInterface {
                return $kernel->handle($request)->primaryResult();
            };
        } else {
            $metrics = new HttpServerMetricsInstrumentation($composition);
            $trace = new HttpServerTraceInstrumentation($composition);
            $operation = function () use ($metrics, $trace, $kernel, $request, &$activeDuringOperation): ResponseInterface {
            return $metrics->measure(
                $request,
                function (ServerRequestInterface $instrumentedRequest) use ($trace, $kernel, &$activeDuringOperation): ResponseInterface {
                    return $trace->trace(
                        $instrumentedRequest,
                        function (ServerRequestInterface $request) use ($kernel, &$activeDuringOperation): ResponseInterface {
                            $activeDuringOperation = Span::getCurrent()->getContext()->isValid();

                            return $kernel->handle($request)->primaryResult();
                        },
                    );
                },
            );
            };
        }

        return new ObserveBenchmarkFixture($operation, function () use (&$activeDuringOperation): bool {
            return $activeDuringOperation;
        });
    }

    public function queueJobFixture(string $mode, ?TracerProviderInterface $tracerProvider = null): ObserveBenchmarkFixture
    {
        $composition = $this->composition($mode, $this->resource('observe-benchmark-queue-job'), $tracerProvider);
        $receiver = new class implements \Evolve\Queue\Contracts\QueuePublisher {
            public ?\Evolve\Queue\Contracts\MessageEnvelope $last = null;

            public function publish(\Evolve\Queue\Contracts\QueueName $queue, \Evolve\Queue\Contracts\MessageEnvelope $message): void
            {
                $this->last = $message;
            }
        };
        $publisher = $mode === 'bare' ? $receiver : new \Evolve\Observe\Queue\QueuePublisherInstrumentation($composition, $receiver);
        $consumer = new \Evolve\Observe\Queue\JobExecutionContextInstrumentation($composition);
        $trace = new ExecutionTraceInstrumentation($composition);
        $registry = new ServiceRegistry();
        $registry->freeze();
        $core = $mode === 'bare'
            ? new ExecutionOrchestrator($registry)
            : new ExecutionOrchestrator($registry, $trace, [$trace]);
        $queue = new \Evolve\Queue\Contracts\QueueName('benchmark');
        $message = new \Evolve\Queue\Contracts\MessageEnvelope('payload');
        $activeDuringOperation = false;

        return new ObserveBenchmarkFixture(
            function () use ($mode, $publisher, $receiver, $consumer, $core, $queue, $message, &$activeDuringOperation): string {
                $publisher->publish($queue, $message);
                $execution = function () use ($core, &$activeDuringOperation): \Evolve\Core\Execution\ExecutionOutcome {
                    return $core->execute(ExecutionKind::QueueMessage, static function () use (&$activeDuringOperation): string {
                        $activeDuringOperation = Span::getCurrent()->getContext()->isValid();

                        return 'ok';
                    });
                };
                $outcome = $mode === 'bare' ? $execution() : $consumer->run($receiver->last, $execution);

                return $outcome->primaryResult();
            },
            static function () use (&$activeDuringOperation): bool {
                return $activeDuringOperation;
            },
        );
    }

    public function databaseFixture(string $mode, ?TracerProviderInterface $tracerProvider = null): ObserveBenchmarkFixture
    {
        $composition = $this->composition($mode, $this->resource('observe-benchmark-database'), $tracerProvider);
        $connection = new class implements \Evolve\Database\Contracts\DatabaseConnection {
            public function execute(\Evolve\Database\Contracts\DatabaseStatement $statement): int
            {
                return 7;
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
        $active = $mode === 'bare' ? $connection : new \Evolve\Observe\Database\DatabaseConnectionInstrumentation($composition, $connection);
        $statement = new \Evolve\Database\Contracts\DatabaseStatement('SELECT 1');

        return new ObserveBenchmarkFixture(
            static fn(): int => $active->execute($statement),
            static fn(): bool => false,
        );
    }

    public function cacheFixture(string $mode, ?TracerProviderInterface $tracerProvider = null): ObserveBenchmarkFixture
    {
        $composition = $this->composition($mode, $this->resource('observe-benchmark-cache'), $tracerProvider);
        $cache = new class implements \Psr\SimpleCache\CacheInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                return 'cached';
            }
            public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
            {
                return true;
            }
            public function delete(string $key): bool
            {
                return true;
            }
            public function clear(): bool
            {
                return true;
            }
            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }
            public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
            {
                return true;
            }
            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }
            public function has(string $key): bool
            {
                return true;
            }
        };
        $active = $mode === 'bare' ? $cache : new \Evolve\Observe\Cache\CacheInstrumentation($composition, $cache);

        return new ObserveBenchmarkFixture(
            static fn(): mixed => $active->get('benchmark-key'),
            static fn(): bool => false,
        );
    }

    public function storageFixture(string $mode, ?TracerProviderInterface $tracerProvider = null): ObserveBenchmarkFixture
    {
        $composition = $this->composition($mode, $this->resource('observe-benchmark-storage'), $tracerProvider);
        $reader = new class implements \Evolve\Storage\Contracts\ReadableObject {
            public function read(int $maxBytes): string
            {
                return 'bytes';
            }
            public function close(): void {}
        };
        $storage = new class ($reader) implements \Evolve\Storage\Contracts\ObjectStorage {
            public function __construct(private \Evolve\Storage\Contracts\ReadableObject $reader) {}
            public function put(\Evolve\Storage\Contracts\StorageKey $key, iterable $chunks): void {}
            public function open(\Evolve\Storage\Contracts\StorageKey $key): \Evolve\Storage\Contracts\ReadableObject
            {
                return $this->reader;
            }
            public function delete(\Evolve\Storage\Contracts\StorageKey $key): void {}
        };
        $active = $mode === 'bare' ? $storage : new \Evolve\Observe\Storage\ObjectStorageInstrumentation($composition, $storage);
        $key = new \Evolve\Storage\Contracts\StorageKey('benchmark-key');

        return new ObserveBenchmarkFixture(
            static function () use ($active, $key): ?string {
                $opened = $active->open($key);
                $value = $opened?->read(16);
                $opened?->close();

                return $value;
            },
            static fn(): bool => false,
        );
    }

    public function httpClientFixture(string $mode, ?TracerProviderInterface $tracerProvider = null): ObserveBenchmarkFixture
    {
        $composition = $this->composition($mode, $this->resource('observe-benchmark-http-client'), $tracerProvider);
        $client = new class implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new \Nyholm\Psr7\Response(204);
            }
        };
        $middleware = new \Evolve\Observe\Http\HttpClientInstrumentation($composition);
        $request = new \Nyholm\Psr7\Request('GET', 'https://example.test/benchmark');

        return new ObserveBenchmarkFixture(
            static fn(): int => ($mode === 'bare'
                ? $client->sendRequest($request)
                : $middleware->process($request, $client))->getStatusCode(),
            static fn(): bool => false,
        );
    }
    private function composition(
        string $mode,
        ResourceInfo $resource,
        ?TracerProviderInterface $tracerProvider,
    ): OpenTelemetryComposition {
        if ($mode !== 'enabled') {
            return OpenTelemetryComposition::disabled();
        }

        return new OpenTelemetryComposition(
            enabled: true,
            tracerProvider: $tracerProvider ?? new TracerProvider([], resource: $resource),
            meterProvider: new NoopMeterProvider(),
            resource: $resource,
        );
    }

    private function resource(string $serviceName): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create([
            ServiceAttributes::SERVICE_NAME => $serviceName,
        ]));
    }
}

final class ObserveBenchmarkFixture
{
    /** @var \Closure(): mixed */
    private \Closure $operation;

    /** @var \Closure(): bool */
    private \Closure $activeProbe;

    /**
     * @param callable(): mixed $operation
     * @param callable(): bool $activeProbe
     */
    public function __construct(callable $operation, callable $activeProbe)
    {
        $this->operation = $operation(...);
        $this->activeProbe = $activeProbe(...);
    }

    public function invoke(): mixed
    {
        return ($this->operation)();
    }

    public function activeDuringOperation(): bool
    {
        return ($this->activeProbe)();
    }
}
