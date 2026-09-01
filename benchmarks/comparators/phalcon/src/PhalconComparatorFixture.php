<?php

declare(strict_types=1);

namespace Benchmark\Phalcon;

use Evolve\Benchmarks\Comparator\PhalconAvailability;
use Evolve\Benchmarks\Comparator\PreparedComparatorFixture;
use Evolve\Benchmarks\Comparator\PreparedScenario;

final class PhalconComparatorFixture implements PreparedComparatorFixture
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
                    $this->createApplication();

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
        $application = $this->createApplication();

        return new PreparedScenario(
            $scenarioId,
            'prepared_warm_http_request',
            1,
            fn(): array => $this->withPreparedHttpMetadata($this->handle($application, $path) + $extra),
        );
    }

    private function middlewareSubject(): PreparedScenario
    {
        $state = (object) ['order' => []];
        $application = $this->createApplication($state);

        return new PreparedScenario(
            'http_middleware',
            'prepared_warm_http_request',
            1,
            function () use ($application, $state): array {
                $state->order = [];
                $result = $this->handle($application, '/benchmark-middleware');
                $result['middleware_order'] = $state->order;
                $result['middleware_model'] = 'before';

                return $this->withPreparedHttpMetadata($result);
            },
        );
    }

    private function repeatedWarmSubject(int $requestCount): PreparedScenario
    {
        $application = $this->createApplication();

        return new PreparedScenario(
            'http_repeated_warm',
            'prepared_repeated_warm_requests',
            1,
            function () use ($application, $requestCount): array {
                $last = null;

                for ($i = 0; $i < max(1, $requestCount); ++$i) {
                    $last = $this->handle($application, '/benchmark');
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

        if ($middlewareState !== null) {
            foreach ([1, 2, 3, 4, 5] as $index) {
                $app->before(static function () use ($middlewareState, $index): bool {
                    $middlewareState->order[] = $index;

                    return true;
                });
            }
        }

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
        $app->get('/benchmark-middleware', static function () use ($responseClass): object {
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
