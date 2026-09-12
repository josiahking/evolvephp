<?php

declare(strict_types=1);

namespace Evolve\Bridge\LegacyHttp;

final class LegacyRemoteClient
{
    private const RESERVED_AUTHENTICATION_HEADERS = [
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

    /** @var string */
    private $endpoint;
    /** @var LegacyRemoteTransport */
    private $transport;
    /** @var LegacyRemoteClientAuthenticator */
    private $authenticator;
    /** @var LegacyRemoteCodec */
    private $codec;
    /** @var float */
    private $connectTimeoutSeconds;
    /** @var float */
    private $requestTimeoutSeconds;

    public function __construct(string $endpoint, LegacyRemoteTransport $transport, LegacyRemoteClientAuthenticator $authenticator, ?LegacyRemoteCodec $codec = null, float $connectTimeoutSeconds = 2.0, float $requestTimeoutSeconds = 10.0)
    {
        $this->assertEndpoint($endpoint);
        $this->assertTimeout($connectTimeoutSeconds, 'connect timeout');
        $this->assertTimeout($requestTimeoutSeconds, 'request timeout');

        $this->endpoint = $endpoint;
        $this->transport = $transport;
        $this->authenticator = $authenticator;
        $this->codec = $codec ?: new LegacyRemoteCodec();
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
        $this->requestTimeoutSeconds = $requestTimeoutSeconds;
    }

    public function invoke(LegacyRemoteInvocation $invocation): LegacyRemoteClientResult
    {
        if ($invocation->deadlineExpired()) {
            return LegacyRemoteClientResult::failure('timeout', 'remote_bridge_deadline_expired', 'Remote Bridge deadline has expired.');
        }

        try {
            $body = $this->codec->encodeInvocation($invocation);
        } catch (\LengthException $exception) {
            return LegacyRemoteClientResult::failure('protocol', 'remote_bridge_request_too_large', 'Remote Bridge request exceeds the maximum protocol size.');
        } catch (\InvalidArgumentException $exception) {
            return LegacyRemoteClientResult::failure('protocol', 'remote_bridge_invalid_request', 'Remote Bridge request could not be encoded.');
        }

        if (strlen($body) > LegacyRemoteProtocol::MAX_BODY_BYTES) {
            return LegacyRemoteClientResult::failure('protocol', 'remote_bridge_request_too_large', 'Remote Bridge request exceeds the maximum protocol size.');
        }

        try {
            $headers = $this->headers($invocation);
        } catch (\Throwable $exception) {
            return LegacyRemoteClientResult::failure('authentication', 'remote_bridge_invalid_authentication_headers', 'Remote Bridge authentication headers are invalid.');
        }

        try {
            $response = $this->transport->send(LegacyRemoteProtocol::HTTP_METHOD, $this->endpoint, $headers, $body, $this->connectTimeoutSeconds, $this->requestTimeoutSeconds);
        } catch (LegacyRemoteClientResult $result) {
            return $result;
        } catch (\Throwable $exception) {
            return LegacyRemoteClientResult::uncertainTransportFailure();
        }

        return $this->received($response, $invocation);
    }

    private function received(LegacyRemoteTransportResponse $response, LegacyRemoteInvocation $invocation): LegacyRemoteClientResult
    {
        $status = $response->status();

        if ($status >= 300 && $status < 400) {
            return LegacyRemoteClientResult::failure('protocol', 'remote_bridge_redirect_response', 'Remote Bridge response must not be a direct redirect.');
        }

        if (!LegacyRemoteProtocol::mediaTypeMatches($response->headerLine('content-type'))) {
            return LegacyRemoteClientResult::failure('protocol', 'remote_bridge_unsupported_media_type', 'Remote Bridge response used an unsupported media type.');
        }

        if (strlen($response->body()) > LegacyRemoteProtocol::MAX_BODY_BYTES) {
            return LegacyRemoteClientResult::failure('protocol', 'remote_bridge_response_too_large', 'Remote Bridge response exceeds the maximum protocol size.');
        }

        try {
            $result = $this->codec->decodeResult($response->body());
        } catch (\InvalidArgumentException $exception) {
            return LegacyRemoteClientResult::failure('protocol', 'remote_bridge_invalid_response', 'Remote Bridge response is invalid.');
        }

        if ($result->outerStatus() !== $status) {
            return LegacyRemoteClientResult::failure('protocol', 'remote_bridge_status_mismatch', 'Remote Bridge response status does not match the protocol envelope.');
        }

        if ($result->requestIdentifier() !== $invocation->requestIdentifier()) {
            return LegacyRemoteClientResult::failure('protocol', 'remote_bridge_request_mismatch', 'Remote Bridge response request identifier does not match.');
        }

        if ($result->correlationIdentifier() !== $invocation->correlationIdentifier()) {
            return LegacyRemoteClientResult::failure('protocol', 'remote_bridge_correlation_mismatch', 'Remote Bridge response correlation identifier does not match.');
        }

        return $result;
    }

    /** @return array<string, list<string>> */
    private function headers(LegacyRemoteInvocation $invocation): array
    {
        $headers = [
            'accept' => [LegacyRemoteProtocol::MEDIA_TYPE],
            'content-type' => [LegacyRemoteProtocol::MEDIA_TYPE],
            'x-correlation-id' => [$invocation->correlationIdentifier()],
            'x-request-id' => [$invocation->requestIdentifier()],
        ];

        if ($invocation->idempotencyKey() !== null) {
            $headers['x-idempotency-key'] = [$invocation->idempotencyKey()];
        }

        $authHeaders = $this->authenticator->authenticationHeaders($invocation);

        foreach ($authHeaders as $name => $values) {
            $this->assertAuthenticationHeader($name, $values);
            $headers[strtolower($name)] = $values;
        }

        ksort($headers);

        return $headers;
    }

    /**
     * @param mixed $values
     */
    private function assertAuthenticationHeader(string $name, $values): void
    {
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('Remote Bridge authentication header name is invalid.');
        }

        if (in_array(strtolower($name), self::RESERVED_AUTHENTICATION_HEADERS, true)) {
            throw new \InvalidArgumentException('Remote Bridge authentication header is reserved.');
        }

        if (!is_array($values) || $values === [] || array_keys($values) !== range(0, count($values) - 1)) {
            throw new \InvalidArgumentException('Remote Bridge authentication header values are invalid.');
        }

        foreach ($values as $value) {
            if (!is_string($value) || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException('Remote Bridge authentication header value is invalid.');
            }
        }
    }

    private function assertEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);

        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Remote Bridge endpoint must be an absolute HTTP or HTTPS URI.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || !isset($parts['host']) || $parts['host'] === '') {
            throw new \InvalidArgumentException('Remote Bridge endpoint must be an absolute HTTP or HTTPS URI with a host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Remote Bridge endpoint must not embed credentials.');
        }

        if (isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Remote Bridge endpoint must not include a URI fragment.');
        }
    }

    private function assertTimeout(float $seconds, string $label): void
    {
        if ($seconds <= 0.0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Remote Bridge ' . $label . ' must be positive.');
        }
    }
}
