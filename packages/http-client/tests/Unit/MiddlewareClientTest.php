<?php

declare(strict_types=1);

namespace Evolve\Http\Client\Tests\Unit;

use Evolve\Http\Client\ClientMiddleware;
use Evolve\Http\Client\MiddlewareClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;

final class MiddlewareClientTest extends TestCase
{
    public function test_it_is_a_psr_18_client(): void
    {
        $reflection = new ReflectionClass(MiddlewareClient::class);

        self::assertTrue($reflection->implementsInterface(ClientInterface::class));
    }

    public function test_zero_middleware_delegates_original_request_and_preserves_response_identity(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $transport = $this->createMock(ClientInterface::class);
        $transport->expects(self::once())->method('sendRequest')->with(self::identicalTo($request))->willReturn($response);

        self::assertSame($response, (new MiddlewareClient($transport))->sendRequest($request));
    }

    public function test_one_middleware_receives_request_and_downstream_client(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $transport = $this->createMock(ClientInterface::class);
        $transport->expects(self::once())->method('sendRequest')->with(self::identicalTo($request))->willReturn($response);
        $calls = 0;
        $middleware = $this->middleware(function (RequestInterface $received, ClientInterface $next) use ($request, &$calls): ResponseInterface {
            ++$calls;
            self::assertSame($request, $received);

            return $next->sendRequest($received);
        });

        self::assertSame($response, (new MiddlewareClient($transport, $middleware))->sendRequest($request));
        self::assertSame(1, $calls);
    }

    public function test_middleware_order_is_deterministic_and_unwinds_in_reverse(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $order = [];
        $transport = $this->transport(function (RequestInterface $received) use (&$order, $request, $response): ResponseInterface {
            self::assertSame($request, $received);
            $order[] = 'transport';

            return $response;
        });
        $first = $this->recordingMiddleware('first', $order);
        $second = $this->recordingMiddleware('second', $order);

        self::assertSame($response, (new MiddlewareClient($transport, $first, $second))->sendRequest($request));
        self::assertSame(['first-before', 'second-before', 'transport', 'second-after', 'first-after'], $order);
    }

    public function test_middleware_can_substitute_request(): void
    {
        $original = $this->createMock(RequestInterface::class);
        $derived = $this->createStub(RequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $original->expects(self::once())->method('withHeader')->with('x-test', 'derived')->willReturn($derived);
        $transport = $this->createMock(ClientInterface::class);
        $transport->expects(self::once())->method('sendRequest')->with(self::identicalTo($derived))->willReturn($response);
        $middleware = $this->middleware(static fn(RequestInterface $request, ClientInterface $next): ResponseInterface => $next->sendRequest($request->withHeader('x-test', 'derived')));

        self::assertSame($response, (new MiddlewareClient($transport, $middleware))->sendRequest($original));
    }

    public function test_middleware_can_short_circuit_without_calling_transport(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $transport = $this->createMock(ClientInterface::class);
        $transport->expects(self::never())->method('sendRequest');
        $middleware = $this->middleware(static fn(RequestInterface $request, ClientInterface $next): ResponseInterface => $response);

        self::assertSame($response, (new MiddlewareClient($transport, $middleware))->sendRequest($request));
    }

    #[DataProvider('normalHttpErrorResponses')]
    public function test_http_error_status_remains_a_normal_response(int $status): void
    {
        $request = $this->createStub(RequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);

        self::assertSame($response, (new MiddlewareClient($this->transportReturning($response)))->sendRequest($request));
    }

    /** @return iterable<string, array{int}> */
    public static function normalHttpErrorResponses(): iterable
    {
        yield 'client error' => [404];
        yield 'server error' => [503];
    }

    #[DataProvider('psr18Exceptions')]
    public function test_downstream_psr_18_exception_identity_is_preserved(ClientExceptionInterface $exception): void
    {
        $request = $this->createStub(RequestInterface::class);
        $transport = $this->createStub(ClientInterface::class);
        $transport->method('sendRequest')->willThrowException($exception);

        try {
            (new MiddlewareClient($transport))->sendRequest($request);
            self::fail('The downstream exception should propagate.');
        } catch (ClientExceptionInterface $caught) {
            self::assertSame($exception, $caught);
        }
    }

    /** @return iterable<string, array{ClientExceptionInterface}> */
    public static function psr18Exceptions(): iterable
    {
        $request = self::createStub(RequestInterface::class);

        yield 'request exception' => [new class ('request', $request) extends \RuntimeException implements RequestExceptionInterface {
            public function __construct(string $message, private RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        }];
        yield 'network exception' => [new class ('network', $request) extends \RuntimeException implements NetworkExceptionInterface {
            public function __construct(string $message, private RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        }];
        yield 'generic client exception' => [new class ('client') extends \RuntimeException implements ClientExceptionInterface {}];
    }

    /** @param callable(RequestInterface): ResponseInterface $callback */
    private function transport(callable $callback): ClientInterface
    {
        return new class ($callback) implements ClientInterface {
            /** @param callable(RequestInterface): ResponseInterface $callback */
            public function __construct(private $callback) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->callback)($request);
            }
        };
    }

    private function transportReturning(ResponseInterface $response): ClientInterface
    {
        return $this->transport(static fn(RequestInterface $request): ResponseInterface => $response);
    }

    /** @param callable(RequestInterface, ClientInterface): ResponseInterface $callback */
    private function middleware(callable $callback): ClientMiddleware
    {
        return new class ($callback) implements ClientMiddleware {
            /** @param callable(RequestInterface, ClientInterface): ResponseInterface $callback */
            public function __construct(private $callback) {}

            public function process(RequestInterface $request, ClientInterface $next): ResponseInterface
            {
                return ($this->callback)($request, $next);
            }
        };
    }

    /** @param list<string> $order */
    private function recordingMiddleware(string $name, array &$order): ClientMiddleware
    {
        return $this->middleware(static function (RequestInterface $request, ClientInterface $next) use ($name, &$order): ResponseInterface {
            $order[] = $name . '-before';
            $response = $next->sendRequest($request);
            $order[] = $name . '-after';

            return $response;
        });
    }
}
