<?php

declare(strict_types=1);

namespace Benchmark\Symfony;

use Evolve\Benchmarks\Comparator\PreparedComparatorFixture;
use Evolve\Benchmarks\Comparator\PreparedScenario;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class SymfonyComparatorFixture implements PreparedComparatorFixture
{
    public function id(): string
    {
        return 'symfony';
    }

    public function availability(): array
    {
        return ['available' => class_exists(HttpKernel::class), 'status' => class_exists(HttpKernel::class) ? 'available' : 'unavailable', 'reason' => class_exists(HttpKernel::class) ? null : 'Symfony dependencies are not installed'];
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
            fn(): array => $this->withPreparedHttpMetadata($this->handle($kernel, $path) + $extra),
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
        $context = new RequestContext();
        $matcher = new UrlMatcher($this->routes(), $context);
        $dispatcher = new EventDispatcher();

        if ($middlewareState !== null) {
            for ($i = 1; $i <= 5; ++$i) {
                $dispatcher->addListener(
                    KernelEvents::REQUEST,
                    static function (RequestEvent $event) use ($middlewareState, $i): void {
                        $middlewareState->order[] = $i;
                    },
                    50 - $i,
                );
            }
        }

        $dispatcher->addSubscriber(new RouterListener($matcher, new RequestStack(), $context, null, null, false));

        return new HttpKernel($dispatcher, new ControllerResolver(), null, new ArgumentResolver());
    }

    private function routes(): RouteCollection
    {
        $routes = new RouteCollection();
        $routes->add('benchmark.static', new Route('/benchmark', [
            '_controller' => static fn(): Response => new Response('symfony:static', 200),
        ]));
        $routes->add('benchmark.parameterized', new Route('/benchmark/{id}', [
            '_controller' => static fn(Request $request): Response => new Response('symfony:parameterized:' . $request->attributes->get('id'), 200),
        ]));
        $routes->add('benchmark.middleware', new Route('/benchmark-middleware', [
            '_controller' => static fn(): Response => new Response('symfony:middleware', 200),
        ]));

        return $routes;
    }

    private function handle(HttpKernel $kernel, string $path): array
    {
        try {
            $response = $kernel->handle(Request::create($path, 'GET'), HttpKernelInterface::MAIN_REQUEST, true);
        } catch (NotFoundHttpException|ResourceNotFoundException) {
            return [
                'scenario_id' => 'http_not_found',
                'status_code' => 404,
                'body' => 'symfony:not-found',
                'not_found' => true,
            ];
        }

        return [
            'status_code' => $response->getStatusCode(),
            'body' => $response->getContent(),
        ];
    }
}
