<?php

declare(strict_types=1);

namespace Benchmark\Slim;

use Evolve\Benchmarks\Comparator\PreparedComparatorFixture;
use Evolve\Benchmarks\Comparator\PreparedScenario;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

final class SlimComparatorFixture implements PreparedComparatorFixture
{
    public function id(): string
    {
        return 'slim';
    }

    public function availability(): array
    {
        return ['available' => class_exists(AppFactory::class), 'status' => class_exists(AppFactory::class) ? 'available' : 'unavailable', 'reason' => class_exists(AppFactory::class) ? null : 'Slim dependencies are not installed'];
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
                    $this->createApp();

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
        $app = $this->createApp();

        return new PreparedScenario(
            $scenarioId,
            'prepared_warm_http_request',
            1,
            fn(): array => $this->withPreparedHttpMetadata($this->handle($app, $path) + $extra),
        );
    }

    private function middlewareSubject(): PreparedScenario
    {
        $state = (object) ['order' => []];
        $app = $this->createApp($state);

        return new PreparedScenario(
            'http_middleware',
            'prepared_warm_http_request',
            1,
            function () use ($app, $state): array {
                $state->order = [];
                $result = $this->handle($app, '/benchmark-middleware');
                $result['middleware_order'] = $state->order;

                return $this->withPreparedHttpMetadata($result);
            },
        );
    }

    private function repeatedWarmSubject(int $requestCount): PreparedScenario
    {
        $app = $this->createApp();

        return new PreparedScenario(
            'http_repeated_warm',
            'prepared_repeated_warm_requests',
            1,
            function () use ($app, $requestCount): array {
                $last = null;

                for ($i = 0; $i < max(1, $requestCount); ++$i) {
                    $last = $this->handle($app, '/benchmark');
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

    private function createApp(?object $middlewareState = null): App
    {
        $factory = new Psr17Factory();
        AppFactory::setResponseFactory($factory);
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        $app->get('/benchmark', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write('slim:static');

            return $response;
        });
        $app->get('/benchmark/{id}', static function (ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface {
            $response->getBody()->write('slim:parameterized:' . $args['id']);

            return $response;
        });
        $middlewareRoute = $app->get('/benchmark-middleware', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write('slim:middleware');

            return $response;
        });

        if ($middlewareState !== null) {
            for ($i = 5; $i >= 1; --$i) {
                $index = $i;
                $middlewareRoute->add(static function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($middlewareState, $index): ResponseInterface {
                    $middlewareState->order[] = $index;

                    return $handler->handle($request);
                });
            }
        }

        return $app;
    }

    private function handle(App $app, string $path): array
    {
        $factory = new Psr17Factory();
        $response = $app->handle($factory->createServerRequest('GET', $path));

        return [
            'status_code' => $response->getStatusCode(),
            'body' => $response->getStatusCode() === 404 ? 'slim:not-found' : (string) $response->getBody(),
            'not_found' => $response->getStatusCode() === 404,
        ];
    }
}
