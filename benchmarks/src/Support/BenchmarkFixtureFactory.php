<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Support;

use Evolve\Contracts\Component\ComponentDependency;
use Evolve\Contracts\Component\ComponentDependencyKind;
use Evolve\Contracts\Component\ComponentGraphRelations;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Contracts\Component\ComponentType;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\ApplicationKernel;
use Evolve\Core\Component\ComponentBootstrapper;
use Evolve\Core\Configuration\ArrayConfiguration;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Core\Instrumentation\Observation;
use Evolve\Core\Instrumentation\ObservationSink;
use Evolve\Http\HttpKernel;
use Evolve\Http\Middleware\MiddlewarePipeline;
use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteCollection;
use Evolve\Http\Routing\RouteMatcher;
use Evolve\Http\Routing\RoutingRequestHandler;
use Evolve\Testing\Component\ComponentDefinitionFixture;
use Evolve\Testing\Component\ComponentEntryPointFixture;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BenchmarkFixtureFactory
{
    /**
     * @return array{registry: ServiceRegistry, container: ContainerInterface}
     */
    public static function containerFixture(int $serviceCount): array
    {
        $registry = new ServiceRegistry();

        for ($i = 0; $i < $serviceCount; ++$i) {
            $registry->register('bench.application.' . $i, ServiceLifetime::Application, static fn(): object => new \stdClass());
            $registry->register('bench.execution.' . $i, ServiceLifetime::Execution, static fn(): object => new \stdClass());
            $registry->register('bench.transient.' . $i, ServiceLifetime::Transient, static fn(): object => new \stdClass());
        }

        return [
            'registry' => $registry,
            'container' => $registry->freeze(),
        ];
    }

    /**
     * @return array{matcher: RouteMatcher, request: ServerRequestInterface, routes: RouteCollection}
     */
    public static function routeMatchingFixture(int $routeCount, string $category, string $position = 'first'): array
    {
        $target = self::targetOffset($routeCount, $position);
        $routes = self::routes($routeCount, $category, $target);
        $factory = new Psr17Factory();

        $method = $category === 'method-mismatch' ? 'POST' : 'GET';
        $path = match ($category) {
            'parameterized' => '/bench/' . $target,
            'miss', 'not-found' => '/bench/missing',
            default => '/bench/static-' . $target,
        };

        return [
            'matcher' => new RouteMatcher($routes),
            'request' => $factory->createServerRequest($method, $path),
            'routes' => $routes,
        ];
    }

    /**
     * @return array{handler: RoutingRequestHandler, request: ServerRequestInterface, last_request: callable(): ?ServerRequestInterface}
     */
    public static function routingHandlerFixture(int $routeCount, string $category): array
    {
        $state = (object) ['lastRequest' => null];
        $terminal = self::terminalHandler($state);
        $routes = self::routes($routeCount, $category, 0, $terminal);
        $factory = new Psr17Factory();
        $request = match ($category) {
            'method-mismatch' => $factory->createServerRequest('POST', '/bench/static-0'),
            'not-found' => $factory->createServerRequest('GET', '/bench/missing'),
            default => $factory->createServerRequest('GET', '/bench/static-0'),
        };

        return [
            'handler' => new RoutingRequestHandler(new RouteMatcher($routes)),
            'request' => $request,
            'last_request' => static fn(): ?ServerRequestInterface => $state->lastRequest,
        ];
    }

    /**
     * @return array{pipeline: MiddlewarePipeline, request: ServerRequestInterface, counter: callable(): int}
     */
    public static function middlewareFixture(int $depth, string $mode): array
    {
        $factory = new Psr17Factory();
        $state = (object) ['counter' => 0];
        $middleware = [];
        $shortCircuitIndex = match ($mode) {
            'early-short-circuit' => 0,
            'middle-short-circuit' => max(0, intdiv($depth, 2)),
            default => null,
        };

        for ($i = 0; $i < $depth; ++$i) {
            $middleware[] = new class($factory, $state, $i, $shortCircuitIndex) implements MiddlewareInterface {
                public function __construct(
                    private Psr17Factory $factory,
                    private object $state,
                    private int $index,
                    private ?int $shortCircuitIndex,
                ) {}

                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    ++$this->state->counter;

                    if ($this->shortCircuitIndex === $this->index) {
                        return $this->factory->createResponse(204);
                    }

                    return $handler->handle($request);
                }
            };
        }

        $terminal = new class($factory) implements RequestHandlerInterface {
            public function __construct(private Psr17Factory $factory) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };

        return [
            'pipeline' => new MiddlewarePipeline($middleware, $terminal),
            'request' => $factory->createServerRequest('GET', '/bench/middleware'),
            'counter' => static fn(): int => $state->counter,
        ];
    }

    /**
     * @return array{kernel: HttpKernel, request: ServerRequestInterface, last_request: callable(): ?ServerRequestInterface}
     */
    public static function httpKernelFixture(string $scenario): array
    {
        $state = (object) ['lastRequest' => null];
        $terminal = self::terminalHandler($state);
        $middlewareDepth = $scenario === 'middleware-heavy' ? 20 : 0;
        $category = $scenario === 'parameterized' ? 'parameterized' : 'static';
        $routes = self::routes(10, $category, 5, $terminal);
        $handler = new RoutingRequestHandler(
            new RouteMatcher($routes),
            self::passThroughMiddleware($middlewareDepth),
        );

        $registry = new ServiceRegistry();
        $registry->freeze();

        $factory = new Psr17Factory();
        $request = match ($scenario) {
            'parameterized' => $factory->createServerRequest('GET', '/bench/5'),
            'not-found' => $factory->createServerRequest('GET', '/bench/missing'),
            'method-mismatch' => $factory->createServerRequest('POST', '/bench/static-5'),
            default => $factory->createServerRequest('GET', '/bench/static-5'),
        };

        return [
            'kernel' => new HttpKernel($handler, new ExecutionOrchestrator($registry)),
            'request' => $request,
            'last_request' => static fn(): ?ServerRequestInterface => $state->lastRequest,
        ];
    }

    /**
     * @return array{
     *     orchestrator: ExecutionOrchestrator,
     *     operation: callable(mixed, ExecutionScope): string,
     *     reset_count: callable(): int,
     *     observation_count: callable(): int,
     *     last_scope: callable(): ?ExecutionScope
     * }
     */
    public static function executionOrchestratorFixture(int $resetParticipants = 0, bool $withObservationSink = false): array
    {
        $registry = new ServiceRegistry();
        $registry->register('bench.execution', ServiceLifetime::Execution, static fn(): object => new \stdClass());
        $registry->freeze();
        $state = (object) [
            'resetCount' => 0,
            'observationCount' => 0,
            'lastScope' => null,
        ];

        $sink = $withObservationSink
            ? new class($state) implements ObservationSink {
                public function __construct(private object $state) {}

                public function observe(Observation $observation): void
                {
                    ++$this->state->observationCount;
                }
            }
            : null;

        return [
            'orchestrator' => new ExecutionOrchestrator($registry, $sink),
            'operation' => static function ($_context, ExecutionScope $scope) use ($resetParticipants, $state): string {
                $state->lastScope = $scope;
                $scope->get('bench.execution');

                for ($i = 0; $i < $resetParticipants; ++$i) {
                    $scope->registerResetParticipant('participant-' . $i, new class($state) implements ResetParticipant {
                        public function __construct(private object $state) {}

                        public function reset(): void
                        {
                            ++$this->state->resetCount;
                        }
                    });
                }

                return 'ok';
            },
            'reset_count' => static fn(): int => $state->resetCount,
            'observation_count' => static fn(): int => $state->observationCount,
            'last_scope' => static fn(): ?ExecutionScope => $state->lastScope,
        ];
    }

    /**
     * @return array{
     *     kernel: ApplicationKernel,
     *     register_count: callable(): int,
     *     boot_count: callable(): int,
     *     ready_count: callable(): int
     * }
     */
    public static function applicationBootFixture(int $componentCount): array
    {
        $state = (object) [
            'registerCount' => 0,
            'bootCount' => 0,
            'readyCount' => 0,
        ];
        $definitions = [];
        $enabled = [];

        for ($i = 0; $i < $componentCount; ++$i) {
            $identifier = 'bench/component-' . $i;
            $enabled[] = $identifier;
            $dependencies = $i === 0
                ? []
                : [new ComponentDependency(new ComponentIdentifier('bench/component-' . ($i - 1)), ComponentDependencyKind::Required)];

            $definitions[] = new ComponentDefinitionFixture(
                new ComponentIdentifier($identifier),
                ComponentType::Module,
                static fn() => new ComponentEntryPointFixture(
                    register: static function (ServiceDefinitionRegistrar $registrar) use ($state, $i): void {
                        ++$state->registerCount;
                        $registrar->registerApplication('bench.component.' . $i, static fn(): object => new \stdClass());
                    },
                    boot: static function () use ($state): void {
                        ++$state->bootCount;
                    },
                    ready: static function () use ($state): void {
                        ++$state->readyCount;
                    },
                ),
                new ComponentGraphRelations(dependencies: $dependencies),
            );
        }

        return [
            'kernel' => new ApplicationKernel(
                new ArrayConfiguration(['evolve' => ['components' => ['enabled' => $enabled]]]),
                services: new ServiceRegistry(),
                components: new ComponentBootstrapper($definitions),
            ),
            'register_count' => static fn(): int => $state->registerCount,
            'boot_count' => static fn(): int => $state->bootCount,
            'ready_count' => static fn(): int => $state->readyCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function persistentSequentialExecutionEvidence(int $iterations, int $checkpointEvery): array
    {
        $fixture = self::executionOrchestratorFixture(resetParticipants: 1);
        $checkpoints = [[
            'execution_count' => 0,
            'current_bytes' => memory_get_usage(true),
            'peak_bytes' => memory_get_peak_usage(true),
        ]];

        for ($i = 1; $i <= $iterations; ++$i) {
            $fixture['orchestrator']->execute(ExecutionKind::HttpRequest, $fixture['operation']);

            if ($i % $checkpointEvery === 0 || $i === $iterations) {
                $checkpoints[] = [
                    'execution_count' => $i,
                    'current_bytes' => memory_get_usage(true),
                    'peak_bytes' => memory_get_peak_usage(true),
                ];
            }
        }

        return [
            'label' => 'persistent-style sequential execution evidence',
            'execution_count' => $iterations,
            'reset_count' => $fixture['reset_count'](),
            'checkpoints' => $checkpoints,
            'retained_bytes_after_cleanup' => memory_get_usage(true),
        ];
    }

    private static function targetOffset(int $routeCount, string $position): int
    {
        return match ($position) {
            'middle' => intdiv($routeCount, 2),
            'last' => max(0, $routeCount - 1),
            default => 0,
        };
    }

    private static function routes(int $routeCount, string $category, int $target, ?RequestHandlerInterface $handler = null): RouteCollection
    {
        $handler ??= self::terminalHandler((object) ['lastRequest' => null]);
        $routes = [];

        for ($i = 0; $i < $routeCount; ++$i) {
            $path = $category === 'parameterized' && $i === $target ? '/bench/{id}' : '/bench/static-' . $i;
            $routes[] = new Route(['GET'], $path, $handler);
        }

        return new RouteCollection($routes);
    }

    private static function terminalHandler(object $state): RequestHandlerInterface
    {
        $factory = new Psr17Factory();

        return new class($factory, $state) implements RequestHandlerInterface {
            public function __construct(
                private Psr17Factory $factory,
                private object $state,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->state->lastRequest = $request;

                return $this->factory->createResponse(200);
            }
        };
    }

    /**
     * @return list<MiddlewareInterface>
     */
    private static function passThroughMiddleware(int $depth): array
    {
        $middleware = [];

        for ($i = 0; $i < $depth; ++$i) {
            $middleware[] = new class implements MiddlewareInterface {
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    return $handler->handle($request);
                }
            };
        }

        return $middleware;
    }
}
