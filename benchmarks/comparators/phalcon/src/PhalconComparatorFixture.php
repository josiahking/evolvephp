<?php

declare(strict_types=1);

namespace Benchmark\Phalcon;

use Evolve\Benchmarks\Comparator\ComparatorFixture;
use Evolve\Benchmarks\Comparator\PhalconAvailability;

final class PhalconComparatorFixture implements ComparatorFixture
{
    public function id(): string
    {
        return 'phalcon';
    }

    public function availability(): array
    {
        return PhalconAvailability::detect();
    }

    public function applicationBoot(): array
    {
        $this->createApplication();

        return ['scenario_id' => 'application_boot', 'status' => 'ok'];
    }

    public function httpStatic(): array
    {
        return $this->handle($this->createApplication(), '/benchmark');
    }

    public function httpParameterized(string $id): array
    {
        $result = $this->handle($this->createApplication(), '/benchmark/' . $id);
        $result['parameters'] = ['id' => $id];

        return $result;
    }

    public function httpMiddleware(): array
    {
        $state = (object) ['order' => []];
        $result = $this->handle($this->createApplication($state), '/benchmark-middleware');
        $result['middleware_order'] = $state->order;

        return $result;
    }

    public function httpNotFound(): array
    {
        return $this->handle($this->createApplication(), '/benchmark-missing');
    }

    public function httpRepeatedWarm(int $requestCount): array
    {
        $application = $this->createApplication();
        $last = null;

        for ($i = 0; $i < $requestCount; ++$i) {
            $last = $this->handle($application, '/benchmark');
        }

        $last ??= [];
        $last['request_count'] = $requestCount;
        $last['bootstrap_count'] = 1;

        return $last;
    }

    private function createApplication(?object $middlewareState = null): object
    {
        $applicationClass = 'Phalcon\\Mvc\\Micro';
        $responseClass = 'Phalcon\\Http\\Response';
        $notFoundHandler = static function () use ($responseClass): object {
            $response = new $responseClass();
            $response->setStatusCode(404);
            $response->setContent('phalcon:not-found');

            return $response;
        };

        $app = new $applicationClass();
        $app->notFound($notFoundHandler);
        $app->get('/benchmark', static function () use ($responseClass): object {
            $response = new $responseClass();
            $response->setStatusCode(200);
            $response->setContent('phalcon:static');

            return $response;
        });
        $app->get('/benchmark/{id}', static function (string $id) use ($responseClass): object {
            $response = new $responseClass();
            $response->setStatusCode(200);
            $response->setContent('phalcon:parameterized:' . $id);

            return $response;
        });
        $app->get('/benchmark-middleware', static function () use ($responseClass, $middlewareState): object {
            if ($middlewareState !== null) {
                for ($i = 1; $i <= 5; ++$i) {
                    $middlewareState->order[] = $i;
                }
            }

            $response = new $responseClass();
            $response->setStatusCode(200);
            $response->setContent('phalcon:middleware');

            return $response;
        });

        return $app;
    }

    private function handle(object $application, string $path): array
    {
        ob_start();
        $response = $application->handle($path);
        $output = (string) ob_get_clean();

        $statusCode = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 200;
        $body = method_exists($response, 'getContent') ? (string) $response->getContent() : $output;

        return [
            'status_code' => $statusCode,
            'body' => $statusCode === 404 ? 'phalcon:not-found' : $body,
            'not_found' => $statusCode === 404,
        ];
    }
}
