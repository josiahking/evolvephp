<?php

declare(strict_types=1);

namespace Evolve\Bridge\Remote;

use DateTimeImmutable;
use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Contracts\BridgeErrorKind;
use Evolve\Bridge\Psr\EmbeddedBridgeAdapter;
use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @experimental
 */
final readonly class RemoteBridgeServerHandler implements RequestHandlerInterface
{
    public function __construct(
        private EmbeddedBridgeAdapter $adapter,
        private RemoteBridgeCodec $codec,
        private RemoteBridgeAuthenticator $authenticator,
        private ResponseFactoryInterface $responses,
        private ServerRequestFactoryInterface $serverRequests,
        private StreamFactoryInterface $streams,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== RemoteBridgeProtocol::HTTP_METHOD) {
            return $this->error('remote_bridge_method_not_allowed', 'Remote Bridge requires POST requests.', 405, BridgeErrorKind::Protocol);
        }

        if (!RemoteBridgeProtocol::mediaTypeMatches($request->getHeaderLine('content-type'))) {
            return $this->error('remote_bridge_unsupported_media_type', 'Remote Bridge requires a supported JSON media type.', 415, BridgeErrorKind::Protocol);
        }

        try {
            $invocation = $this->codec->decodeInvocation((string) $request->getBody());
        } catch (InvalidArgumentException) {
            return $this->error('remote_bridge_invalid_request', 'Remote Bridge request is invalid.', 400, BridgeErrorKind::Protocol);
        }

        $authError = $this->authenticator->authenticate($request, $invocation);

        if ($authError !== null) {
            $status = match ($authError->kind()) {
                BridgeErrorKind::Authentication => 401,
                BridgeErrorKind::Authorization => 403,
                default => 403,
            };

            return $this->errorResult($status, RemoteBridgeResult::error(
                $invocation->requestIdentifier(),
                $invocation->correlationIdentifier(),
                $status,
                $this->safeError($authError),
            ));
        }

        if ($invocation->deadlineExpired(new DateTimeImmutable())) {
            return $this->errorResult(408, RemoteBridgeResult::error(
                $invocation->requestIdentifier(),
                $invocation->correlationIdentifier(),
                408,
                new BridgeError(BridgeErrorKind::Timeout, 'remote_bridge_deadline_expired', 'Remote Bridge deadline has expired.', false),
            ));
        }

        $result = $this->adapter->invoke($this->delegatedRequest($invocation), $invocation->context());

        if ($result->error() !== null && $result->response() === null) {
            return $this->errorResult(502, RemoteBridgeResult::error(
                $invocation->requestIdentifier(),
                $invocation->correlationIdentifier(),
                502,
                $this->safeError($result->error()),
            ));
        }

        if ($result->response() === null) {
            return $this->errorResult(502, RemoteBridgeResult::error(
                $invocation->requestIdentifier(),
                $invocation->correlationIdentifier(),
                502,
                new BridgeError(BridgeErrorKind::Execution, 'remote_bridge_no_response', 'Remote Bridge execution did not produce a response.', false),
            ));
        }

        $error = $result->error() === null ? null : $this->safeError($result->error());

        return $this->resultResponse(RemoteBridgeResult::applicationResponse(
            $invocation->requestIdentifier(),
            $invocation->correlationIdentifier(),
            $result->response()->getStatusCode(),
            $this->forwardedResponseHeaders($result->response()),
            (string) $result->response()->getBody(),
            $error,
            $result->isReusable(),
            $result->requiresQuarantine(),
        ));
    }

    private function delegatedRequest(RemoteBridgeInvocation $invocation): ServerRequestInterface
    {
        $request = $this->serverRequests
            ->createServerRequest($invocation->method(), $invocation->target())
            ->withBody($this->streams->createStream($invocation->body()))
            ->withAttribute(RemoteBridgeInvocation::class, $invocation)
            ->withAttribute('evolve.bridge.remote.payload', $invocation->payload());

        foreach ($invocation->forwardableHeaders() as $name => $values) {
            $request = $request->withHeader($name, $values);
        }

        return $request;
    }

    /**
     * @return array<string, list<string>>
     */
    private function forwardedResponseHeaders(ResponseInterface $response): array
    {
        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $normalized = strtolower($name);

            if (in_array($normalized, ['content-type', 'cache-control', 'etag', 'last-modified'], true)) {
                $headers[$normalized] = array_values($values);
            }
        }

        ksort($headers);

        return $headers;
    }

    private function error(string $code, string $message, int $status, BridgeErrorKind $kind): ResponseInterface
    {
        return $this->errorResult($status, RemoteBridgeResult::error(
            'unknown',
            'unknown',
            $status,
            new BridgeError($kind, $code, $message, false),
        ));
    }

    private function errorResult(int $status, RemoteBridgeResult $result): ResponseInterface
    {
        return $this->resultResponse($result, $status);
    }

    private function resultResponse(RemoteBridgeResult $result, ?int $status = null): ResponseInterface
    {
        return $this->responses
            ->createResponse($status ?? $result->outerStatus())
            ->withHeader('content-type', RemoteBridgeProtocol::MEDIA_TYPE)
            ->withBody($this->streams->createStream($this->codec->encodeResult($result)));
    }

    private function safeError(BridgeError $error): BridgeError
    {
        $kind = $error->kind();

        if (in_array($kind, [BridgeErrorKind::Authentication, BridgeErrorKind::Authorization, BridgeErrorKind::Timeout, BridgeErrorKind::Protocol, BridgeErrorKind::ResetOrQuarantine], true)) {
            return $error;
        }

        return new BridgeError(BridgeErrorKind::Execution, 'remote_bridge_execution_failed', 'Remote Bridge delegated execution failed.', false);
    }
}
