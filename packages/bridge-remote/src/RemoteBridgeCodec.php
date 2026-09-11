<?php

declare(strict_types=1);

namespace Evolve\Bridge\Remote;

use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Contracts\BridgeErrorKind;
use InvalidArgumentException;
use JsonException;
use ValueError;

/**
 * @experimental
 */
final class RemoteBridgeCodec
{
    public function encodeInvocation(RemoteBridgeInvocation $invocation): string
    {
        return $this->encode([
            'body' => $invocation->body(),
            'caller_id' => $invocation->callerIdentifier(),
            'correlation_id' => $invocation->correlationIdentifier(),
            'deadline' => $invocation->deadline(),
            'headers' => $this->normalizeHeaders($invocation->headers()),
            'idempotency_key' => $invocation->idempotencyKey(),
            'locale' => $invocation->locale(),
            'method' => $invocation->method(),
            'operation' => $invocation->operation(),
            'payload' => $invocation->payload(),
            'principal_id' => $invocation->principalIdentifier(),
            'protocol' => RemoteBridgeProtocol::MEDIA_TYPE,
            'request_id' => $invocation->requestIdentifier(),
            'target' => $invocation->target(),
            'tenant_id' => $invocation->tenantIdentifier(),
            'timezone' => $invocation->timezone(),
            'trace' => $this->sortedStringMap($invocation->trace()),
            'version' => RemoteBridgeProtocol::VERSION,
        ]);
    }

    public function decodeInvocation(string $json): RemoteBridgeInvocation
    {
        $data = $this->decode($json);

        if (($data['version'] ?? null) !== RemoteBridgeProtocol::VERSION) {
            throw new InvalidArgumentException('Remote Bridge protocol version is not supported.');
        }

        $this->requireProtocolIdentifier($data);

        foreach (['operation', 'method', 'target', 'request_id', 'correlation_id', 'caller_id'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new InvalidArgumentException('Remote Bridge invocation is missing required field ' . $field . '.');
            }
        }

        return new RemoteBridgeInvocation(
            operation: $data['operation'],
            method: $data['method'],
            target: $data['target'],
            headers: $this->requireHeaders($data['headers'] ?? []),
            body: $this->requireOptionalString($data, 'body') ?? '',
            payload: $data['payload'] ?? null,
            requestIdentifier: $data['request_id'],
            correlationIdentifier: $data['correlation_id'],
            callerIdentifier: $data['caller_id'],
            principalIdentifier: $this->requireOptionalString($data, 'principal_id'),
            tenantIdentifier: $this->requireOptionalString($data, 'tenant_id'),
            locale: $this->requireOptionalString($data, 'locale'),
            timezone: $this->requireOptionalString($data, 'timezone'),
            deadline: $this->requireOptionalString($data, 'deadline'),
            idempotencyKey: $this->requireOptionalString($data, 'idempotency_key'),
            trace: $this->requireStringMap($data['trace'] ?? []),
        );
    }

    public function encodeResult(RemoteBridgeResult $result): string
    {
        $error = $result->bridgeError();

        return $this->encode([
            'application' => $result->outcome() === 'application' ? [
                'body' => $result->applicationBody(),
                'headers' => $this->normalizeHeaders($result->applicationHeaders()),
                'status' => $result->applicationStatus(),
            ] : null,
            'bridge_error' => $error === null ? null : [
                'code' => $error->code(),
                'kind' => $error->kind()->value,
                'message' => $error->message(),
                'retryable' => $error->isRetryable(),
            ],
            'correlation_id' => $result->correlationIdentifier(),
            'outcome' => $result->outcome(),
            'outer_status' => $result->outerStatus(),
            'protocol' => RemoteBridgeProtocol::MEDIA_TYPE,
            'requires_quarantine' => $result->requiresQuarantine(),
            'request_id' => $result->requestIdentifier(),
            'reusable' => $result->isReusable(),
            'version' => RemoteBridgeProtocol::VERSION,
        ]);
    }

    public function decodeResult(string $json): RemoteBridgeResult
    {
        $data = $this->decode($json);

        if (($data['version'] ?? null) !== RemoteBridgeProtocol::VERSION) {
            throw new InvalidArgumentException('Remote Bridge protocol version is not supported.');
        }

        $this->requireProtocolIdentifier($data);

        foreach (['outcome', 'request_id', 'correlation_id', 'outer_status', 'reusable', 'requires_quarantine'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException('Remote Bridge result is missing required field ' . $field . '.');
            }
        }

        if ($data['outcome'] === 'application') {
            $application = $data['application'] ?? null;

            if (!is_array($application) || !isset($application['status']) || !is_int($application['status'])) {
                throw new InvalidArgumentException('Remote Bridge result application response is invalid.');
            }

            $outerStatus = $this->requireInt($data['outer_status'], 'outer_status');

            if ($outerStatus !== 200) {
                throw new InvalidArgumentException('Remote Bridge application results require outer status 200.');
            }

            $error = null;

            if (($data['bridge_error'] ?? null) !== null) {
                if (!is_array($data['bridge_error'])) {
                    throw new InvalidArgumentException('Remote Bridge result error is invalid.');
                }

                $error = $this->decodeBridgeError($data['bridge_error']);
            }

            return RemoteBridgeResult::applicationResponse(
                $this->requireString($data['request_id'], 'request_id'),
                $this->requireString($data['correlation_id'], 'correlation_id'),
                $application['status'],
                $this->requireHeaders($application['headers'] ?? []),
                $application['body'] ?? null,
                $error,
                $this->requireBool($data['reusable'], 'reusable'),
                $this->requireBool($data['requires_quarantine'], 'requires_quarantine'),
            );
        }

        if ($data['outcome'] === 'bridge_error') {
            $error = $data['bridge_error'] ?? null;

            if (!is_array($error)) {
                throw new InvalidArgumentException('Remote Bridge result error is invalid.');
            }

            if (($data['application'] ?? null) !== null) {
                throw new InvalidArgumentException('Remote Bridge error results must not include an application response.');
            }

            $reusable = $this->requireBool($data['reusable'], 'reusable');
            $requiresQuarantine = $this->requireBool($data['requires_quarantine'], 'requires_quarantine');

            if (!$reusable) {
                throw new InvalidArgumentException('Remote Bridge protocol failures must be reusable.');
            }

            if ($requiresQuarantine) {
                throw new InvalidArgumentException('Remote Bridge protocol failures must not claim quarantine.');
            }

            return RemoteBridgeResult::error(
                $this->requireString($data['request_id'], 'request_id'),
                $this->requireString($data['correlation_id'], 'correlation_id'),
                $this->requireInt($data['outer_status'], 'outer_status'),
                $this->decodeBridgeError($error),
            );
        }

        throw new InvalidArgumentException('Remote Bridge result outcome is invalid.');
    }

    public static function assertTransportValue(mixed $value, string $field): void
    {
        self::assertSafeValue($value, $field, 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        if (strlen($json) > RemoteBridgeProtocol::MAX_BODY_BYTES) {
            throw new InvalidArgumentException('Remote Bridge JSON exceeds the maximum protocol size.');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Remote Bridge JSON is malformed.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('Remote Bridge JSON must decode to an object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        self::assertTransportValue($data, 'protocol');
        $this->sortRecursive($data);

        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new InvalidArgumentException('Remote Bridge JSON could not be encoded.');
        }
    }

    private static function assertSafeValue(mixed $value, string $field, int $depth): void
    {
        if ($depth > 64) {
            throw new InvalidArgumentException('Remote Bridge ' . $field . ' nesting is too deep.');
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            self::assertSafeString($value, $field);

            return;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Remote Bridge ' . $field . ' contains a non-finite float.');
            }

            return;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('Remote Bridge ' . $field . ' contains a transport-unsafe value.');
        }

        foreach ($value as $key => $nested) {
            self::assertSafeValue($nested, $field, $depth + 1);
        }
    }

    private static function assertSafeString(mixed $value, string $field): void
    {
        if (!is_string($value)) {
            return;
        }

        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Remote Bridge ' . $field . ' contains unsafe string data.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireProtocolIdentifier(array $data): void
    {
        if (($data['protocol'] ?? null) !== RemoteBridgeProtocol::MEDIA_TYPE) {
            throw new InvalidArgumentException('Remote Bridge protocol identifier is not supported.');
        }
    }

    /**
     * @param array<string, mixed> $error
     */
    private function decodeBridgeError(array $error): BridgeError
    {
        try {
            $kind = BridgeErrorKind::from($this->requireString($error['kind'] ?? null, 'kind'));
        } catch (ValueError) {
            throw new InvalidArgumentException('Remote Bridge result error kind is invalid.');
        }

        return new BridgeError(
            $kind,
            $this->requireString($error['code'] ?? null, 'code'),
            $this->requireString($error['message'] ?? null, 'message'),
            $this->requireBool($error['retryable'] ?? null, 'retryable'),
        );
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<string, list<string>>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            $normalized[strtolower($name)] = $values;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireOptionalString(array $data, string $field): ?string
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }

        return $this->requireString($data[$field], $field);
    }

    private function requireString(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Remote Bridge ' . $field . ' must be a string.');
        }

        return $value;
    }

    private function requireInt(mixed $value, string $field): int
    {
        if (!is_int($value)) {
            throw new InvalidArgumentException('Remote Bridge ' . $field . ' must be an integer.');
        }

        return $value;
    }

    private function requireBool(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('Remote Bridge ' . $field . ' must be a boolean.');
        }

        return $value;
    }

    /**
     * @return array<string, list<string>>
     */
    private function requireHeaders(mixed $value): array
    {
        if ($value === []) {
            return [];
        }

        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Remote Bridge headers must be an object.');
        }

        $headers = [];

        foreach ($value as $name => $values) {
            if (!is_string($name) || !is_array($values)) {
                throw new InvalidArgumentException('Remote Bridge headers must contain string lists.');
            }

            $headers[strtolower($name)] = [];

            foreach ($values as $headerValue) {
                $headers[strtolower($name)][] = $this->requireString($headerValue, 'header value');
            }
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function requireStringMap(mixed $value): array
    {
        if ($value === []) {
            return [];
        }

        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Remote Bridge trace must be an object.');
        }

        $strings = [];

        foreach ($value as $name => $traceValue) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('Remote Bridge trace keys must be strings.');
            }

            $strings[$name] = $this->requireString($traceValue, 'trace value');
        }

        ksort($strings);

        return $strings;
    }

    /**
     * @param array<string, string> $value
     * @return array<string, string>
     */
    private function sortedStringMap(array $value): array
    {
        ksort($value);

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function sortRecursive(array &$data): void
    {
        foreach (array_keys($data) as $key) {
            if (is_array($data[$key])) {
                $nested = $data[$key];
                $this->sortRecursive($nested);
                $data[$key] = $nested;
            }
        }

        if (!array_is_list($data)) {
            ksort($data);
        }
    }
}
