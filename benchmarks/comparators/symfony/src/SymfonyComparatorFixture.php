<?php

declare(strict_types=1);

namespace Benchmark\Symfony;

use Evolve\Benchmarks\Comparator\ComparatorFixture;
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

final class SymfonyComparatorFixture implements ComparatorFixture
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
