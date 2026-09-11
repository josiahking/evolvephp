<?php

declare(strict_types=1);

namespace Evolve\Bridge\Laravel;

use Evolve\Bridge\Contracts\BridgeContext;
use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Contracts\BridgeErrorKind;
use Evolve\Bridge\Psr\EmbeddedBridgeAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * @experimental
 */
final readonly class LaravelBridgeAdapter
{
    /**
     * @var list<string>
     */
    private const REQUEST_HEADER_ALLOWLIST = [
        'accept',
        'content-type',
        'traceparent',
        'tracestate',
    ];

    /**
     * @var list<string>
     */
    private const RESPONSE_HEADER_ALLOWLIST = [
        'content-type',
        'cache-control',
        'etag',
        'last-modified',
        'location',
        'retry-after',
    ];

    public function __construct(
        private EmbeddedBridgeAdapter $embedded,
        private ServerRequestFactoryInterface $serverRequests,
        private StreamFactoryInterface $streams,
    ) {}

    public function invoke(Request $request, BridgeContext $context): LaravelBridgeResult
    {
        try {
            $psrRequest = $this->translateRequest($request);
        } catch (Throwable) {
            return new LaravelBridgeResult(null, $this->translationError('laravel_request_translation_failed', 'Laravel request could not be safely translated for delegated Evolve execution.'), false);
        }

        $embeddedResult = $this->embedded->invoke($psrRequest, $context);

        if ($embeddedResult->response() === null) {
            return new LaravelBridgeResult(null, $embeddedResult->error(), $embeddedResult->requiresQuarantine());
        }

        try {
            $laravelResponse = $this->translateResponse($embeddedResult->response());
        } catch (Throwable) {
            if ($embeddedResult->requiresQuarantine()) {
                return new LaravelBridgeResult(null, $embeddedResult->error(), true);
            }

            return new LaravelBridgeResult(null, $this->translationError('laravel_response_translation_failed', 'Evolve response could not be safely translated for the Laravel host.'), false);
        }

        return new LaravelBridgeResult($laravelResponse, $embeddedResult->error(), $embeddedResult->requiresQuarantine());
    }

    private function translateRequest(Request $request): ServerRequestInterface
    {
        $psrRequest = $this->serverRequests->createServerRequest($request->getMethod(), $request->getUri(), []);

        foreach (self::REQUEST_HEADER_ALLOWLIST as $header) {
            if (! $request->headers->has($header)) {
                continue;
            }

            $psrRequest = $psrRequest->withHeader($header, $request->headers->all($header));
        }

        $query = $this->sanitizeArray($request->query->all());
        $parsedBody = $this->sanitizeArray($request->request->all());

        return $psrRequest
            ->withBody($this->streams->createStream($request->getContent()))
            ->withQueryParams($query)
            ->withParsedBody($parsedBody);
    }

    private function translateResponse(ResponseInterface $response): Response
    {
        $laravelResponse = new Response(
            (string) $response->getBody(),
            $response->getStatusCode(),
        );

        foreach (self::RESPONSE_HEADER_ALLOWLIST as $header) {
            if (! $response->hasHeader($header)) {
                continue;
            }

            $laravelResponse->headers->set($header, $response->getHeader($header));

        }

        return $laravelResponse;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function sanitizeArray(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            $sanitized[$key] = $this->sanitizeValue($value);
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $this->sanitizeArray($value);
        }

        throw new \InvalidArgumentException('Delegated request data must not contain host-framework objects.');
    }

    private function translationError(string $code, string $message): BridgeError
    {
        return new BridgeError(BridgeErrorKind::Translation, $code, $message, false);
    }
}
