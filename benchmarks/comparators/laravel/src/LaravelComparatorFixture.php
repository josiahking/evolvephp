<?php

declare(strict_types=1);

namespace Benchmark\Laravel;

use Evolve\Benchmarks\Comparator\PreparedComparatorFixture;
use Evolve\Benchmarks\Comparator\PreparedScenario;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\CallableDispatcher;
use Illuminate\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class LaravelComparatorFixture implements PreparedComparatorFixture
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
                    $this->createRouter();

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
        $router = $this->createRouter();

        return new PreparedScenario(
            $scenarioId,
            'prepared_warm_http_request',
            1,
            fn(): array => $this->withPreparedHttpMetadata($this->dispatch($router, $path) + $extra),
        );
    }

    private function middlewareSubject(): PreparedScenario
    {
        $router = $this->createRouter();

        return new PreparedScenario(
            'http_middleware',
            'prepared_warm_http_request',
            1,
            function () use ($router): array {
                MiddlewareState::$order = [];
                $result = $this->dispatch($router, '/benchmark-middleware');
                $result['middleware_order'] = MiddlewareState::$order;

                return $this->withPreparedHttpMetadata($result);
            },
        );
    }

    private function repeatedWarmSubject(int $requestCount): PreparedScenario
    {
        $router = $this->createRouter();

        return new PreparedScenario(
            'http_repeated_warm',
            'prepared_repeated_warm_requests',
            1,
            function () use ($router, $requestCount): array {
                $last = null;

                for ($i = 0; $i < max(1, $requestCount); ++$i) {
                    $last = $this->dispatch($router, '/benchmark');
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
