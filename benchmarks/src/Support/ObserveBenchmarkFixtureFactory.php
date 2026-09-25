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
