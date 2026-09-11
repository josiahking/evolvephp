<?php

declare(strict_types=1);

namespace Evolve\Bridge\Remote;

use DateTimeImmutable;
use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Contracts\BridgeErrorKind;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * @experimental
 */
final readonly class RemoteBridgeClient
{
    private const array RESERVED_AUTHENTICATION_HEADERS = [
        'accept',
        'connection',
        'content-length',
        'content-type',
        'host',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'proxy-connection',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
        'via',
        'x-correlation-id',
        'x-idempotency-key',
        'x-request-id',
    ];

    public function __construct(
        private string $endpoint,
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
        private RemoteBridgeCodec $codec,
        private RemoteBridgeClientAuthenticator $authenticator,
    ) {
        $this->assertEndpoint($endpoint);
    }

    public function invoke(RemoteBridgeInvocation $invocation): RemoteBridgeClientResult
    {
        if ($invocation->deadlineExpired(new DateTimeImmutable())) {
            return $this->failure(
                BridgeErrorKind::Timeout,
                'remote_bridge_deadline_expired',
                'Remote Bridge deadline has expired.',
            );
        }

        try {
            $body = $this->codec->encodeInvocation($invocation);
        } catch (InvalidArgumentException) {
            return $this->failure(
                BridgeErrorKind::Protocol,
                'remote_bridge_invalid_request',
                'Remote Bridge request could not be encoded.',
            );
        }

        if (strlen($body) > RemoteBridgeProtocol::MAX_BODY_BYTES) {
            return $this->failure(
                BridgeErrorKind::Protocol,
                'remote_bridge_request_too_large',
                'Remote Bridge request exceeds the maximum protocol size.',
            );
        }

        try {
            $request = $this->requests
                ->createRequest(RemoteBridgeProtocol::HTTP_METHOD, $this->endpoint)
                ->withHeader('content-type', RemoteBridgeProtocol::MEDIA_TYPE)
                ->withHeader('accept', RemoteBridgeProtocol::MEDIA_TYPE)
                ->withBody($this->streams->createStream($body));
        } catch (Throwable) {
            return $this->failure(
                BridgeErrorKind::Configuration,
                'remote_bridge_request_construction_failed',
                'Remote Bridge request could not be constructed.',
            );
        }

        try {
            $request = $this->withAuthenticationHeaders($request, $invocation);
        } catch (InvalidArgumentException) {
            return $this->failure(
                BridgeErrorKind::Authentication,
                'remote_bridge_invalid_authentication_headers',
                'Remote Bridge authentication headers are invalid.',
            );
        }

        try {
            return $this->received($this->httpClient->sendRequest($request), $invocation);
        } catch (RequestExceptionInterface) {
            return $this->failure(
                BridgeErrorKind::Transport,
                'remote_bridge_request_failed',
                'Remote Bridge request could not be issued safely.',
            );
        } catch (NetworkExceptionInterface) {
            return $this->failure(
                BridgeErrorKind::UncertainOutcome,
                'remote_bridge_outcome_uncertain',
                'Remote Bridge transport failed without a response; the outcome is uncertain.',
            );
        } catch (ClientExceptionInterface) {
            return $this->failure(
                BridgeErrorKind::Transport,
                'remote_bridge_transport_failed',
                'Remote Bridge transport failed.',
            );
        }
    }

    private function received(ResponseInterface $response, RemoteBridgeInvocation $invocation): RemoteBridgeClientResult
    {
        $status = $response->getStatusCode();

        if ($status >= 300 && $status < 400) {
            return $this->protocolFailure('remote_bridge_redirect_response', 'Remote Bridge response must not be a direct redirect.');
        }

        if (!$this->responseMediaTypeMatches($response)) {
            return $this->protocolFailure('remote_bridge_unsupported_media_type', 'Remote Bridge response used an unsupported media type.');
        }

        $size = $response->getBody()->getSize();

        if ($size !== null && $size > RemoteBridgeProtocol::MAX_BODY_BYTES) {
            return $this->protocolFailure('remote_bridge_response_too_large', 'Remote Bridge response exceeds the maximum protocol size.');
        }

        $body = (string) $response->getBody();

        if (strlen($body) > RemoteBridgeProtocol::MAX_BODY_BYTES) {
            return $this->protocolFailure('remote_bridge_response_too_large', 'Remote Bridge response exceeds the maximum protocol size.');
        }

        try {
            $result = $this->codec->decodeResult($body);
        } catch (InvalidArgumentException) {
            return $this->protocolFailure('remote_bridge_invalid_response', 'Remote Bridge response is invalid.');
        }

        if ($status !== $result->outerStatus()) {
            return $this->protocolFailure('remote_bridge_status_mismatch', 'Remote Bridge response status does not match the protocol envelope.');
        }

        if ($result->requestIdentifier() !== $invocation->requestIdentifier()) {
            return $this->protocolFailure('remote_bridge_request_mismatch', 'Remote Bridge response request identifier does not match.');
        }

        if ($result->correlationIdentifier() !== $invocation->correlationIdentifier()) {
            return $this->protocolFailure('remote_bridge_correlation_mismatch', 'Remote Bridge response correlation identifier does not match.');
        }

        return RemoteBridgeClientResult::received($result);
    }

    private function protocolFailure(string $code, string $message): RemoteBridgeClientResult
    {
        return $this->failure(BridgeErrorKind::Protocol, $code, $message);
    }

    private function failure(BridgeErrorKind $kind, string $code, string $message): RemoteBridgeClientResult
    {
        return RemoteBridgeClientResult::failure(new BridgeError($kind, $code, $message, false));
    }

    private function withAuthenticationHeaders(RequestInterface $request, RemoteBridgeInvocation $invocation): RequestInterface
    {
        try {
            $headers = $this->authenticationHeaders($request, $invocation);
        } catch (Throwable) {
            throw new InvalidArgumentException('Remote Bridge authentication headers are invalid.');
        }

        foreach ($headers as $name => $values) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('Remote Bridge authentication header name is invalid.');
            }

            $normalized = strtolower($name);

            if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
                throw new InvalidArgumentException('Remote Bridge authentication header name is invalid.');
            }

            if (in_array($normalized, self::RESERVED_AUTHENTICATION_HEADERS, true)) {
                throw new InvalidArgumentException('Remote Bridge authentication header is reserved.');
            }

            if (!is_array($values) || !array_is_list($values) || $values === []) {
                throw new InvalidArgumentException('Remote Bridge authentication header values are invalid.');
            }

            foreach ($values as $value) {
                if (!is_string($value) || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
                    throw new InvalidArgumentException('Remote Bridge authentication header value is invalid.');
                }
            }

            $request = $request->withHeader($name, $values);
        }

        return $request;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function authenticationHeaders(RequestInterface $request, RemoteBridgeInvocation $invocation): array
    {
        return $this->authenticator->authenticationHeaders($request, $invocation);
    }

    private function responseMediaTypeMatches(ResponseInterface $response): bool
    {
        $mediaType = strtolower(trim(explode(';', $response->getHeaderLine('content-type'), 2)[0]));

        return $mediaType === RemoteBridgeProtocol::MEDIA_TYPE;
    }

    private function assertEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);

        if (!is_array($parts)) {
            throw new InvalidArgumentException('Remote Bridge endpoint must be an absolute HTTP or HTTPS URI.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || !isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidArgumentException('Remote Bridge endpoint must be an absolute HTTP or HTTPS URI with a host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Remote Bridge endpoint must not embed credentials.');
        }

        if (isset($parts['fragment'])) {
            throw new InvalidArgumentException('Remote Bridge endpoint must not include a URI fragment.');
        }
    }
}
