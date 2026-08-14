<?php

declare(strict_types=1);

namespace Evolve\Http\Tests\Unit\Health;

use BadMethodCallException;
use Evolve\Http\Health\LivenessHandler;
use Evolve\Http\Health\ReadinessCheck;
use Evolve\Http\Health\ReadinessHandler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use RuntimeException;

final class HealthHandlersTest extends TestCase
{
    public function test_public_health_api_shapes_are_minimal_final_readonly_psr_15_boundaries(): void
    {
        $readinessCheck = new ReflectionClass(ReadinessCheck::class);
        $liveness = new ReflectionClass(LivenessHandler::class);
        $readiness = new ReflectionClass(ReadinessHandler::class);
        $isReady = $readinessCheck->getMethod('isReady');

        self::assertTrue($readinessCheck->isInterface());
        self::assertSame('bool', (string) $isReady->getReturnType());
        self::assertTrue($liveness->isFinal());
        self::assertTrue($liveness->isReadOnly());
        self::assertContains(RequestHandlerInterface::class, class_implements(LivenessHandler::class));
        self::assertTrue($readiness->isFinal());
        self::assertTrue($readiness->isReadOnly());
        self::assertContains(RequestHandlerInterface::class, class_implements(ReadinessHandler::class));
    }

    public function test_liveness_returns_empty_200_and_creates_one_response_per_request(): void
    {
        $factory = new HealthResponseFactory();
        $handler = new LivenessHandler($factory);

        $first = $handler->handle($this->request('/live?secret=one'));
        $second = $handler->handle($this->request('/different?secret=two'));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame('', (string) $first->getBody());
        self::assertSame('', (string) $second->getBody());
        self::assertSame([200, 200], $factory->createdStatuses);
    }

    public function test_liveness_performs_no_readiness_checks_and_ignores_request_data(): void
    {
        $handler = new LivenessHandler(new HealthResponseFactory());

        self::assertSame(200, $handler->handle($this->request('/live', true))->getStatusCode());
    }

    public function test_empty_readiness_check_iterable_is_valid_and_returns_empty_200(): void
    {
        $factory = new HealthResponseFactory();
        $handler = new ReadinessHandler($factory);

        $response = $handler->handle($this->request('/ready'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame([200], $factory->createdStatuses);
    }

    public function test_readiness_generator_is_consumed_once_during_construction_and_reused_safely(): void
    {
        $events = new HealthEventLog();
        $checks = function () use ($events): iterable {
            $events->add('yield A');
            yield new RecordingReadinessCheck('A', true, $events);

            $events->add('yield B');
            yield new RecordingReadinessCheck('B', true, $events);
        };

        $handler = new ReadinessHandler(new HealthResponseFactory(), $checks());

        self::assertSame(['yield A', 'yield B'], $events->all());

        self::assertSame(200, $handler->handle($this->request('/ready'))->getStatusCode());
        self::assertSame(200, $handler->handle($this->request('/ready-again'))->getStatusCode());
        self::assertSame(['yield A', 'yield B', 'check A', 'check B', 'check A', 'check B'], $events->all());
    }

    public function test_readiness_rejects_invalid_entries_eagerly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReadinessHandler(new HealthResponseFactory(), [new RecordingReadinessCheck('valid', true), new \stdClass()]);
    }

    public function test_readiness_preserves_check_order_and_returns_200_when_all_checks_are_ready(): void
    {
        $events = new HealthEventLog();
        $handler = new ReadinessHandler(new HealthResponseFactory(), [
            new RecordingReadinessCheck('A', true, $events),
            new RecordingReadinessCheck('B', true, $events),
            new RecordingReadinessCheck('C', true, $events),
        ]);

        $response = $handler->handle($this->request('/ready'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame(['check A', 'check B', 'check C'], $events->all());
    }

    public function test_readiness_returns_503_and_short_circuits_on_first_false_check(): void
    {
        $events = new HealthEventLog();
        $handler = new ReadinessHandler(new HealthResponseFactory(), [
            new RecordingReadinessCheck('A', true, $events),
            new RecordingReadinessCheck('B', false, $events),
            new RecordingReadinessCheck('C', true, $events),
        ]);

        $response = $handler->handle($this->request('/ready'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame(['check A', 'check B'], $events->all());
    }

    public function test_readiness_returns_503_short_circuits_and_hides_throwing_check_details(): void
    {
        $events = new HealthEventLog();
        $handler = new ReadinessHandler(new HealthResponseFactory(), [
            new RecordingReadinessCheck('A', true, $events),
            new ThrowingReadinessCheck('B', new RuntimeException('database password leaked'), $events),
            new RecordingReadinessCheck('C', true, $events),
        ]);

        $response = $handler->handle($this->request('/ready'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame('', $response->getReasonPhrase());
        self::assertStringNotContainsString('database', (string) $response->getBody());
        self::assertSame(['check A', 'check B'], $events->all());
    }

    public function test_sequential_readiness_calls_do_not_inherit_previous_failure_state(): void
    {
        $check = new QueueingReadinessCheck([false, true]);
        $handler = new ReadinessHandler(new HealthResponseFactory(), [$check]);

        self::assertSame(503, $handler->handle($this->request('/ready'))->getStatusCode());
        self::assertSame(200, $handler->handle($this->request('/ready'))->getStatusCode());
    }

    public function test_health_handlers_do_not_use_request_data_or_static_current_health_state(): void
    {
        $request = $this->request('/ready?token=secret', true);

        self::assertSame(200, (new LivenessHandler(new HealthResponseFactory()))->handle($request)->getStatusCode());
        self::assertSame(
            200,
            (new ReadinessHandler(new HealthResponseFactory(), [new RecordingReadinessCheck('ok', true)]))
                ->handle($request)
                ->getStatusCode(),
        );

        foreach ([LivenessHandler::class, ReadinessHandler::class] as $className) {
            foreach ((new ReflectionClass($className))->getProperties() as $property) {
                self::assertFalse($property->isStatic());
            }
        }
    }

    public function test_health_handlers_do_not_own_or_auto_register_routes(): void
    {
        $liveness = new ReflectionClass(LivenessHandler::class);
        $readiness = new ReflectionClass(ReadinessHandler::class);

        self::assertCount(1, $liveness->getConstructor()?->getParameters() ?? []);
        self::assertSame('responses', $liveness->getConstructor()->getParameters()[0]->getName());
        self::assertSame('responses', $readiness->getConstructor()->getParameters()[0]->getName());
        self::assertSame('checks', $readiness->getConstructor()->getParameters()[1]->getName());
    }

    private function request(string $target, bool $throwOnDataAccess = false): ServerRequestInterface
    {
        return new HealthServerRequest($target, $throwOnDataAccess);
    }
}

final class HealthResponseFactory implements ResponseFactoryInterface
{
    /**
     * @var list<int>
     */
    public array $createdStatuses = [];

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        $this->createdStatuses[] = $code;

        return new HealthResponse($code, $reasonPhrase);
    }
}

final readonly class HealthResponse implements ResponseInterface
{
    public function __construct(
        private int $statusCode,
        private string $reasonPhrase = '',
    ) {}

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function hasHeader(string $name): bool
    {
        return false;
    }

    public function getHeader(string $name): array
    {
        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return '';
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        return $this;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        return $this;
    }

    public function withoutHeader(string $name): MessageInterface
    {
        return $this;
    }

    public function getBody(): StreamInterface
    {
        return new EmptyHealthStream();
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        return new self($code, $reasonPhrase);
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }
}

final class EmptyHealthStream implements StreamInterface
{
    public function __toString(): string
    {
        return '';
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): int
    {
        return 0;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void {}

    public function rewind(): void {}

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new BadMethodCallException('Test stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        return '';
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata(?string $key = null): mixed
    {
        return null;
    }
}

final readonly class HealthServerRequest implements ServerRequestInterface
{
    public function __construct(
        private string $target,
        private bool $throwOnDataAccess = false,
    ) {}

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        $this->failOnDataAccess();

        return ['Authorization' => ['Bearer secret']];
    }

    public function hasHeader(string $name): bool
    {
        $this->failOnDataAccess();

        return false;
    }

    public function getHeader(string $name): array
    {
        $this->failOnDataAccess();

        return [];
    }

    public function getHeaderLine(string $name): string
    {
        $this->failOnDataAccess();

        return '';
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        return $this;
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        return $this;
    }

    public function withoutHeader(string $name): MessageInterface
    {
        return $this;
    }

    public function getBody(): StreamInterface
    {
        $this->failOnDataAccess();

        return new EmptyHealthStream();
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        return $this;
    }

    public function getRequestTarget(): string
    {
        $this->failOnDataAccess();

        return $this->target;
    }

    public function withRequestTarget(string $requestTarget): ServerRequestInterface
    {
        return new self($requestTarget, $this->throwOnDataAccess);
    }

    public function getMethod(): string
    {
        $this->failOnDataAccess();

        return 'GET';
    }

    public function withMethod(string $method): ServerRequestInterface
    {
        return $this;
    }

    public function getUri(): UriInterface
    {
        $this->failOnDataAccess();

        return new HealthUri($this->target);
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): ServerRequestInterface
    {
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getServerParams(): array
    {
        $this->failOnDataAccess();

        return ['REMOTE_ADDR' => '127.0.0.1'];
    }

    /**
     * @return array<string, string>
     */
    public function getCookieParams(): array
    {
        $this->failOnDataAccess();

        return ['session' => 'secret'];
    }

    /**
     * @param array<string, string> $cookies
     */
    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getQueryParams(): array
    {
        $this->failOnDataAccess();

        return ['token' => 'secret'];
    }

    /**
     * @param array<string, string> $query
     */
    public function withQueryParams(array $query): ServerRequestInterface
    {
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getUploadedFiles(): array
    {
        $this->failOnDataAccess();

        return [];
    }

    /**
     * @param array<string, string> $uploadedFiles
     */
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getParsedBody()
    {
        $this->failOnDataAccess();

        return ['password' => 'secret'];
    }

    /**
     * @param array<string, string> $data
     */
    public function withParsedBody($data): ServerRequestInterface
    {
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getAttributes(): array
    {
        $this->failOnDataAccess();

        return ['tenant' => 'secret'];
    }

    public function getAttribute(string $name, $default = null)
    {
        $this->failOnDataAccess();

        return $default;
    }

    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        return $this;
    }

    public function withoutAttribute(string $name): ServerRequestInterface
    {
        return $this;
    }

    private function failOnDataAccess(): void
    {
        if ($this->throwOnDataAccess) {
            throw new BadMethodCallException('Request data must not be read.');
        }
    }
}

final readonly class HealthUri implements UriInterface
{
    public function __construct(private string $path) {}

    public function getScheme(): string
    {
        return '';
    }

    public function getAuthority(): string
    {
        return '';
    }

    public function getUserInfo(): string
    {
        return '';
    }

    public function getHost(): string
    {
        return '';
    }

    public function getPort(): ?int
    {
        return null;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return '';
    }

    public function getFragment(): string
    {
        return '';
    }

    public function withScheme(string $scheme): UriInterface
    {
        return $this;
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        return $this;
    }

    public function withHost(string $host): UriInterface
    {
        return $this;
    }

    public function withPort(?int $port): UriInterface
    {
        return $this;
    }

    public function withPath(string $path): UriInterface
    {
        return new self($path);
    }

    public function withQuery(string $query): UriInterface
    {
        return $this;
    }

    public function withFragment(string $fragment): UriInterface
    {
        return $this;
    }

    public function __toString(): string
    {
        return $this->path;
    }
}

final class RecordingReadinessCheck implements ReadinessCheck
{
    public function __construct(
        private readonly string $name,
        private readonly bool $ready,
        private readonly ?HealthEventLog $events = null,
    ) {}

    public function isReady(): bool
    {
        $this->events?->add('check ' . $this->name);

        return $this->ready;
    }
}

final class ThrowingReadinessCheck implements ReadinessCheck
{
    public function __construct(
        private readonly string $name,
        private readonly RuntimeException $throwable,
        private readonly HealthEventLog $events,
    ) {}

    public function isReady(): bool
    {
        $this->events->add('check ' . $this->name);

        throw $this->throwable;
    }
}

final class QueueingReadinessCheck implements ReadinessCheck
{
    /**
     * @param list<bool> $results
     */
    public function __construct(private array $results) {}

    public function isReady(): bool
    {
        return array_shift($this->results) ?? true;
    }
}

final class HealthEventLog
{
    /**
     * @var list<string>
     */
    private array $events = [];

    public function add(string $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->events;
    }
}
