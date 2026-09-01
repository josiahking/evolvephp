<?php

declare(strict_types=1);

namespace Benchmark\EvolvePHP;

use Evolve\Benchmarks\Comparator\PreparedComparatorFixture;
use Evolve\Benchmarks\Comparator\PreparedScenario;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Http\Exception\RouteNotFound;
use Evolve\Http\HttpKernel;
use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteCollection;
use Evolve\Http\Routing\RouteMatch;
use Evolve\Http\Routing\RouteMatcher;
use Evolve\Http\Routing\RoutingRequestHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class EvolvePhpComparatorFixture implements PreparedComparatorFixture
{
    public function id(): string
    {
        return 'evolvephp';
    }

    public function availability(): array
    {
        return ['available' => true, 'status' => 'available'];
    }

    public function applicationBoot(): array
    {
        return $this->prepareScenario('application_boot')->runOnce();
    }

    public function httpStatic(): array
    {
        return $this->prepareScenario('http_static')->runOnce();
    }

    public function httpParameterized(string $id): array
    {
        return $this->prepareScenario('http_parameterized', ['id' => $id])->runOnce();
    }

    public function httpMiddleware(): array
    {
        return $this->prepareScenario('http_middleware')->runOnce();
    }

    public function httpNotFound(): array
    {
        return $this->prepareScenario('http_not_found')->runOnce();
    }

    public function httpRepeatedWarm(int $requestCount): array
    {
        return $this->prepareScenario('http_repeated_warm', ['request_count' => $requestCount])->runOnce();
    }

    public function prepareScenario(string $scenarioId, array $options = []): PreparedScenario
    {
        return match ($scenarioId) {
            'application_boot' => new PreparedScenario(
                'application_boot',
                'application_boot_constructs_framework',
                0,
                function (): array {
                    $this->createKernel();

                    return [
                        'scenario_id' => 'application_boot',
                        'status' => 'ok',
                        'framework_constructed_in_subject' => true,
                        'framework_prepared_outside_subject' => false,
                    ];
                },
            ),
            'http_static' => $this->warmHttpSubject('http_static', '/benchmark'),
            'http_parameterized' => $this->warmHttpSubject(
                'http_parameterized',
                '/benchmark/' . (string) ($options['id'] ?? '123'),
                ['parameters' => ['id' => (string) ($options['id'] ?? '123')]],
            ),
            'http_middleware' => $this->middlewareSubject(),
            'http_not_found' => $this->warmHttpSubject('http_not_found', '/benchmark-missing'),
            'http_repeated_warm' => $this->repeatedWarmSubject((int) ($options['request_count'] ?? 25)),
            default => throw new \InvalidArgumentException("Unknown comparator scenario '{$scenarioId}'."),
        };
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function warmHttpSubject(string $scenarioId, string $path, array $extra = []): PreparedScenario
    {
        $kernel = $this->createKernel();

        return new PreparedScenario(
            $scenarioId,
            'prepared_warm_http_request',
            1,
            function () use ($kernel, $path, $extra): array {
                return $this->withPreparedHttpMetadata($this->handle($kernel, $path) + $extra);
            },
        );
    }

    private function middlewareSubject(): PreparedScenario
    {
        $state = (object) ['order' => []];
        $kernel = $this->createKernel($state);

        return new PreparedScenario(
            'http_middleware',
            'prepared_warm_http_request',
            1,
            function () use ($kernel, $state): array {
                $state->order = [];
                $result = $this->handle($kernel, '/benchmark-middleware');
                $result['middleware_order'] = $state->order;

                return $this->withPreparedHttpMetadata($result);
            },
        );
    }

    private function repeatedWarmSubject(int $requestCount): PreparedScenario
    {
        $kernel = $this->createKernel();

        return new PreparedScenario(
            'http_repeated_warm',
            'prepared_repeated_warm_requests',
            1,
            function () use ($kernel, $requestCount): array {
                $last = null;

                for ($i = 0; $i < max(1, $requestCount); ++$i) {
                    $last = $this->handle($kernel, '/benchmark');
                }

                $last ??= [];
                $last['request_count'] = max(1, $requestCount);
                $last['bootstrap_count'] = 1;
                $last['framework_constructed_in_subject'] = false;
                $last['normal_framework_path_executed'] = true;
                $last['prepared_framework_instance_reused'] = true;

                return $last;
            },
        );
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function withPreparedHttpMetadata(array $result): array
    {
        $result['framework_constructed_in_subject'] = false;
        $result['normal_framework_path_executed'] = true;

        return $result;
    }

    private function createKernel(?object $middlewareState = null): HttpKernel
    {
        $factory = new Psr17Factory();
        $routes = new RouteCollection([
            new Route(['GET'], '/benchmark', $this->handler($factory, 'static')),
            new Route(['GET'], '/benchmark/{id}', $this->handler($factory, 'parameterized')),
            new Route(['GET'], '/benchmark-middleware', $this->handler($factory, 'middleware')),
        ]);
        $middleware = $middlewareState === null ? [] : $this->middleware($middlewareState);
        $handler = new RoutingRequestHandler(new RouteMatcher($routes), $middleware);
        $services = new ServiceRegistry();
        $services->freeze();

        return new HttpKernel($handler, new ExecutionOrchestrator($services));
    }

    private function handle(HttpKernel $kernel, string $path): array
    {
        $factory = new Psr17Factory();

        try {
            $outcome = $kernel->handle($factory->createServerRequest('GET', $path));

            if ($outcome->primaryFailed()) {
                $throwable = $outcome->primaryThrowableOrFail();

                if ($throwable instanceof RouteNotFound) {
                    return [
                        'scenario_id' => 'http_not_found',
                        'status_code' => 404,
                        'body' => 'evolvephp:not-found',
                        'not_found' => true,
                    ];
                }

                throw $throwable;
            }

            $response = $outcome->primaryResult();
        } catch (RouteNotFound) {
            return [
                'scenario_id' => 'http_not_found',
                'status_code' => 404,
                'body' => 'evolvephp:not-found',
                'not_found' => true,
            ];
        }

        return [
            'status_code' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
        ];
    }

    private function handler(Psr17Factory $factory, string $scenario): RequestHandlerInterface
    {
        return new class ($factory, $scenario) implements RequestHandlerInterface {
            public function __construct(
                private Psr17Factory $factory,
                private string $scenario,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = $this->factory->createResponse(200);
                $match = $request->getAttribute(RouteMatch::class);

                if ($this->scenario === 'parameterized' && $match instanceof RouteMatch) {
                    $id = $match->parameters()['id'] ?? '';
                    $response->getBody()->write('evolvephp:parameterized:' . $id);

                    return $response;
                }

                $response->getBody()->write('evolvephp:' . ($this->scenario === 'middleware' ? 'middleware' : 'static'));

                return $response;
            }
        };
    }

    /**
     * @return list<MiddlewareInterface>
     */
    private function middleware(object $state): array
    {
        $middleware = [];

        for ($i = 1; $i <= 5; ++$i) {
            $middleware[] = new class ($state, $i) implements MiddlewareInterface {
                public function __construct(private object $state, private int $index) {}

                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    $this->state->order[] = $this->index;

                    return $handler->handle($request);
                }
            };
        }

        return $middleware;
    }
}
