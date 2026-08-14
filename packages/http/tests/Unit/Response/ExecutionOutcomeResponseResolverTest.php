<?php

declare(strict_types=1);

namespace Evolve\Http\Tests\Unit\Response;

use BadMethodCallException;
use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Core\Instrumentation\InstrumentationFailure;
use Evolve\Core\Instrumentation\ObservationType;
use Evolve\Http\Exception\MethodNotAllowed;
use Evolve\Http\Exception\RouteNotFound;
use Evolve\Http\Response\ExecutionOutcomeResponseResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use ReflectionClass;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class ExecutionOutcomeResponseResolverTest extends TestCase
{
    public function test_public_api_is_final_readonly(): void
    {
        $reflection = new ReflectionClass(ExecutionOutcomeResponseResolver::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }

    public function test_successful_http_outcome_returns_exact_response_without_using_factory(): void
    {
        $factory = new RecordingResponseFactory();
        $resolver = new ExecutionOutcomeResponseResolver($factory);
        $response = new ResolverResponse(202);

        $result = $resolver->resolve($this->succeeded($response));

        self::assertSame($response, $result);
        self::assertSame([], $factory->createdStatuses);
    }

    public function test_non_http_outcome_is_rejected(): void
    {
        $resolver = new ExecutionOutcomeResponseResolver(new RecordingResponseFactory());

        $this->expectException(InvalidArgumentException::class);

        $resolver->resolve(ExecutionOutcome::succeeded(
            ExecutionIdentifier::generate(),
            ExecutionKind::CliCommand,
            new ResolverResponse(200),
            null,
        ));
    }

    public function test_successful_http_outcome_with_non_response_result_is_rejected_without_factory_use(): void
    {
        $factory = new RecordingResponseFactory();
        $resolver = new ExecutionOutcomeResponseResolver($factory);

        $this->expectException(UnexpectedValueException::class);

        try {
            $resolver->resolve($this->succeeded('not a response'));
        } finally {
            self::assertSame([], $factory->createdStatuses);
        }
    }

    public function test_route_not_found_maps_to_empty_404_without_exposing_details(): void
    {
        $factory = new RecordingResponseFactory();
        $resolver = new ExecutionOutcomeResponseResolver($factory);

        $response = $resolver->resolve($this->failed(new RouteNotFound()));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame(['404'], array_map('strval', $factory->createdStatuses));
        self::assertSame('', $response->getHeaderLine('Allow'));
    }

    public function test_method_not_allowed_maps_to_empty_405_with_exact_allow_header(): void
    {
        $resolver = new ExecutionOutcomeResponseResolver(new RecordingResponseFactory());

        $response = $resolver->resolve($this->failed(new MethodNotAllowed(['GET', 'post', 'PATCH'])));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame(['GET, post, PATCH'], $response->getHeader('Allow'));
        self::assertSame('GET, post, PATCH', $response->getHeaderLine('Allow'));
    }

    public function test_method_not_allowed_does_not_add_head_options_sort_or_change_case(): void
    {
        $resolver = new ExecutionOutcomeResponseResolver(new RecordingResponseFactory());

        $response = $resolver->resolve($this->failed(new MethodNotAllowed(['post', 'GET'])));

        self::assertSame('post, GET', $response->getHeaderLine('Allow'));
        self::assertStringNotContainsString('HEAD', $response->getHeaderLine('Allow'));
        self::assertStringNotContainsString('OPTIONS', $response->getHeaderLine('Allow'));
    }

    public function test_generic_throwable_maps_to_empty_500_without_using_exception_code_or_message(): void
    {
        $resolver = new ExecutionOutcomeResponseResolver(new RecordingResponseFactory());

        $response = $resolver->resolve($this->failed(new RuntimeException('secret failure details', 418)));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame('', $response->getReasonPhrase());
        self::assertStringNotContainsString('secret', (string) $response->getBody());
    }

    public function test_cleanup_failure_does_not_replace_successful_primary_response_or_quarantine_state(): void
    {
        $resolver = new ExecutionOutcomeResponseResolver(new RecordingResponseFactory());
        $response = new ResolverResponse(204);
        $cleanupFailure = new RuntimeException('cleanup secret');
        $outcome = $this->succeeded($response, $cleanupFailure);

        self::assertSame($response, $resolver->resolve($outcome));
        self::assertTrue($outcome->cleanupFailed());
        self::assertTrue($outcome->requiresQuarantine());
        self::assertSame($cleanupFailure, $outcome->cleanupThrowable());
    }

    public function test_cleanup_failure_does_not_replace_primary_route_failure_mapping(): void
    {
        $resolver = new ExecutionOutcomeResponseResolver(new RecordingResponseFactory());
        $cleanupFailure = new RuntimeException('cleanup secret');
        $outcome = $this->failed(new RouteNotFound(), $cleanupFailure);

        $response = $resolver->resolve($outcome);

        self::assertSame(404, $response->getStatusCode());
        self::assertTrue($outcome->cleanupFailed());
        self::assertTrue($outcome->requiresQuarantine());
        self::assertSame($cleanupFailure, $outcome->cleanupThrowable());
    }

    public function test_instrumentation_failures_do_not_change_response_mapping(): void
    {
        $resolver = new ExecutionOutcomeResponseResolver(new RecordingResponseFactory());
        $instrumentationFailure = InstrumentationFailure::fromThrowable(
            ObservationType::ExecutionStarted,
            new RuntimeException('sink secret'),
        );
        $success = new ResolverResponse(201);

        self::assertSame($success, $resolver->resolve($this->succeeded($success, null, [$instrumentationFailure])));
        self::assertSame(404, $resolver->resolve($this->failed(new RouteNotFound(), null, [$instrumentationFailure]))->getStatusCode());
        self::assertSame(405, $resolver->resolve($this->failed(new MethodNotAllowed(['PUT']), null, [$instrumentationFailure]))->getStatusCode());
        self::assertSame(500, $resolver->resolve($this->failed(new RuntimeException('app secret'), null, [$instrumentationFailure]))->getStatusCode());
    }

    public function test_sequential_resolver_reuse_retains_no_previous_outcome_or_response_state(): void
    {
        $resolver = new ExecutionOutcomeResponseResolver(new RecordingResponseFactory());
        $first = new ResolverResponse(299);

        self::assertSame($first, $resolver->resolve($this->succeeded($first)));
        self::assertSame(404, $resolver->resolve($this->failed(new RouteNotFound()))->getStatusCode());
        self::assertSame(500, $resolver->resolve($this->failed(new RuntimeException('secret')))->getStatusCode());
    }

    /**
     * @param list<InstrumentationFailure> $instrumentationFailures
     */
    private function succeeded(mixed $result, ?Throwable $cleanupThrowable = null, array $instrumentationFailures = []): ExecutionOutcome
    {
        return ExecutionOutcome::succeeded(
            ExecutionIdentifier::generate(),
            ExecutionKind::HttpRequest,
            $result,
            $cleanupThrowable,
            $instrumentationFailures,
        );
    }

    /**
     * @param list<InstrumentationFailure> $instrumentationFailures
     */
    private function failed(Throwable $throwable, ?Throwable $cleanupThrowable = null, array $instrumentationFailures = []): ExecutionOutcome
    {
        return ExecutionOutcome::failed(
            ExecutionIdentifier::generate(),
            ExecutionKind::HttpRequest,
            $throwable,
            $cleanupThrowable,
            $instrumentationFailures,
        );
    }
}

final class RecordingResponseFactory implements ResponseFactoryInterface
{
    /**
     * @var list<int>
     */
    public array $createdStatuses = [];

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        $this->createdStatuses[] = $code;

        return new ResolverResponse($code, $reasonPhrase);
    }
}

final readonly class ResolverResponse implements ResponseInterface
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        private int $statusCode,
        private string $reasonPhrase = '',
        private array $headers = [],
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
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->normalizedHeaders());
    }

    public function getHeader(string $name): array
    {
        return $this->normalizedHeaders()[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        $headers = $this->headers;
        $headers[$name] = is_array($value) ? array_values($value) : [$value];

        return new self($this->statusCode, $this->reasonPhrase, $headers);
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        $headers = $this->headers;
        $headers[$name] = array_merge($headers[$name] ?? [], is_array($value) ? array_values($value) : [$value]);

        return new self($this->statusCode, $this->reasonPhrase, $headers);
    }

    public function withoutHeader(string $name): MessageInterface
    {
        $headers = $this->headers;
        $normalizedName = strtolower($name);

        foreach (array_keys($headers) as $headerName) {
            if (strtolower($headerName) === $normalizedName) {
                unset($headers[$headerName]);
            }
        }

        return new self($this->statusCode, $this->reasonPhrase, $headers);
    }

    public function getBody(): StreamInterface
    {
        return new EmptyResolverStream();
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
        return new self($code, $reasonPhrase, $this->headers);
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * @return array<string, list<string>>
     */
    private function normalizedHeaders(): array
    {
        $headers = [];

        foreach ($this->headers as $name => $values) {
            $headers[strtolower($name)] = array_map('strval', $values);
        }

        return $headers;
    }
}

final class EmptyResolverStream implements StreamInterface
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
