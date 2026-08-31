<?php

declare(strict_types=1);

namespace Benchmark\EvolvePHP;

use Evolve\Benchmarks\Comparator\ComparatorFixture;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Container\ServiceRegistry;
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

final class EvolvePhpComparatorFixture implements ComparatorFixture
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
        $this->createKernel();

        return ['scenario_id' => 'application_boot', 'status' => 'ok'];
    }

    public function httpStatic(): array
    {
        return $this->handle($this->createKernel(), '/benchmark');
    }

    public function httpParameterized(string $id): array
    {
        $result = $this->handle($this->createKernel(), '/benchmark/' . $id);
        $result['parameters'] = ['id' => $id];

        return $result;
    }

    public function httpMiddleware(): array
    {
        $state = (object) ['order' => []];
        $result = $this->handle($this->createKernel($state), '/benchmark-middleware');
        $result['middleware_order'] = $state->order;

        return $result;
    }

    public function httpNotFound(): array
    {
        return $this->handle($this->createKernel(), '/benchmark-missing');
    }

    public function httpRepeatedWarm(int $requestCount): array
    {
        $kernel = $this->createKernel();
        $last = null;

        for ($i = 0; $i < $requestCount; ++$i) {
            $last = $this->handle($kernel, '/benchmark');
        }

        $last ??= [];
        $last['request_count'] = $requestCount;
        $last['bootstrap_count'] = 1;

        return $last;
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
        return new class($factory, $scenario) implements RequestHandlerInterface {
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
            $middleware[] = new class($state, $i) implements MiddlewareInterface {
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
