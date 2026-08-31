<?php

declare(strict_types=1);

namespace Benchmark\Laravel;

use Evolve\Benchmarks\Comparator\ComparatorFixture;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\CallableDispatcher;
use Illuminate\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class LaravelComparatorFixture implements ComparatorFixture
{
    public function id(): string
    {
        return 'laravel';
    }

    public function availability(): array
    {
        return ['available' => class_exists(Router::class), 'status' => class_exists(Router::class) ? 'available' : 'unavailable', 'reason' => class_exists(Router::class) ? null : 'Laravel dependencies are not installed'];
    }

    public function applicationBoot(): array
    {
        $this->createRouter();

        return ['scenario_id' => 'application_boot', 'status' => 'ok'];
    }

    public function httpStatic(): array
    {
        return $this->dispatch($this->createRouter(), '/benchmark');
    }

    public function httpParameterized(string $id): array
    {
        $result = $this->dispatch($this->createRouter(), '/benchmark/' . $id);
        $result['parameters'] = ['id' => $id];

        return $result;
    }

    public function httpMiddleware(): array
    {
        MiddlewareState::$order = [];
        $result = $this->dispatch($this->createRouter(), '/benchmark-middleware');
        $result['middleware_order'] = MiddlewareState::$order;

        return $result;
    }

    public function httpNotFound(): array
    {
        return $this->dispatch($this->createRouter(), '/benchmark-missing');
    }

    public function httpRepeatedWarm(int $requestCount): array
    {
        $router = $this->createRouter();
        $last = null;

        for ($i = 0; $i < $requestCount; ++$i) {
            $last = $this->dispatch($router, '/benchmark');
        }

        $last ??= [];
        $last['request_count'] = $requestCount;
        $last['bootstrap_count'] = 1;

        return $last;
    }

    private function createRouter(): Router
    {
        $container = new Container();
        $container->singleton(CallableDispatcherContract::class, static fn(Container $container): CallableDispatcher => new CallableDispatcher($container));
        $router = new Router(new Dispatcher($container), $container);
        $router->aliasMiddleware('bench.1', Middleware1::class);
        $router->aliasMiddleware('bench.2', Middleware2::class);
        $router->aliasMiddleware('bench.3', Middleware3::class);
        $router->aliasMiddleware('bench.4', Middleware4::class);
        $router->aliasMiddleware('bench.5', Middleware5::class);

        $router->get('/benchmark', static fn(): Response => new Response('laravel:static', 200));
        $router->get('/benchmark/{id}', static fn(string $id): Response => new Response('laravel:parameterized:' . $id, 200));
        $router->get('/benchmark-middleware', static fn(): Response => new Response('laravel:middleware', 200))
            ->middleware(['bench.1', 'bench.2', 'bench.3', 'bench.4', 'bench.5']);

        return $router;
    }

    private function dispatch(Router $router, string $path): array
    {
        try {
            $response = $router->dispatch(Request::create($path, 'GET'));
        } catch (NotFoundHttpException) {
            return [
                'scenario_id' => 'http_not_found',
                'status_code' => 404,
                'body' => 'laravel:not-found',
                'not_found' => true,
            ];
        }

        return [
            'status_code' => $response->getStatusCode(),
            'body' => (string) $response->getContent(),
        ];
    }
}
