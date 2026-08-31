<?php

declare(strict_types=1);

namespace Benchmark\Slim;

use Evolve\Benchmarks\Comparator\ComparatorFixture;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

final class SlimComparatorFixture implements ComparatorFixture
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
        $this->createApp();

        return ['scenario_id' => 'application_boot', 'status' => 'ok'];
    }

    public function httpStatic(): array
    {
        return $this->handle($this->createApp(), '/benchmark');
    }

    public function httpParameterized(string $id): array
    {
        $result = $this->handle($this->createApp(), '/benchmark/' . $id);
        $result['parameters'] = ['id' => $id];

        return $result;
    }

    public function httpMiddleware(): array
    {
        $state = (object) ['order' => []];
        $result = $this->handle($this->createApp($state), '/benchmark-middleware');
        $result['middleware_order'] = $state->order;

        return $result;
    }

    public function httpNotFound(): array
    {
        return $this->handle($this->createApp(), '/benchmark-missing');
    }

    public function httpRepeatedWarm(int $requestCount): array
    {
        $app = $this->createApp();
        $last = null;

        for ($i = 0; $i < $requestCount; ++$i) {
            $last = $this->handle($app, '/benchmark');
        }

        $last ??= [];
        $last['request_count'] = $requestCount;
        $last['bootstrap_count'] = 1;

        return $last;
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
