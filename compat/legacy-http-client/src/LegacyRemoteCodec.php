<?php

declare(strict_types=1);

namespace Evolve\Bridge\LegacyHttp;

final class LegacyRemoteCodec
{
    public function encodeInvocation(LegacyRemoteInvocation $invocation): string
    {
        return $this->encode([
            'body' => $invocation->body(),
            'caller_id' => $invocation->callerIdentifier(),
            'correlation_id' => $invocation->correlationIdentifier(),
            'deadline' => $invocation->deadline(),
            'headers' => $invocation->headers(),
            'idempotency_key' => $invocation->idempotencyKey(),
            'locale' => $invocation->locale(),
            'method' => $invocation->method(),
            'operation' => $invocation->operation(),
            'payload' => $invocation->payload(),
            'principal_id' => $invocation->principalIdentifier(),
            'protocol' => LegacyRemoteProtocol::MEDIA_TYPE,
            'request_id' => $invocation->requestIdentifier(),
            'target' => $invocation->target(),
            'tenant_id' => $invocation->tenantIdentifier(),
            'timezone' => $invocation->timezone(),
            'trace' => $this->sortedStringMap($invocation->trace()),
            'version' => LegacyRemoteProtocol::VERSION,
        ]);
    }

    public function decodeResult(string $json): LegacyRemoteClientResult
    {
        $data = $this->decode($json);
        LegacyRemoteProtocol::assertProtocolVersion($data);
        LegacyRemoteProtocol::assertProtocolIdentifier($data);

        foreach (['outcome', 'request_id', 'correlation_id', 'outer_status', 'reusable', 'requires_quarantine'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new \InvalidArgumentException('Remote Bridge result is missing required field ' . $field . '.');
            }
        }

        $outcome = $this->requireString($data['outcome'], 'outcome');
        $requestIdentifier = $this->requireNonBlankString($data['request_id'], 'request_id');
        $correlationIdentifier = $this->requireNonBlankString($data['correlation_id'], 'correlation_id');
        $outerStatus = $this->requireInt($data['outer_status'], 'outer_status');
        $reusable = $this->requireBool($data['reusable'], 'reusable');
        $requiresQuarantine = $this->requireBool($data['requires_quarantine'], 'requires_quarantine');

        if ($outcome === 'application') {
            $application = $data['application'] ?? null;

            if (!is_array($application) || !isset($application['status']) || !is_int($application['status'])) {
                throw new \InvalidArgumentException('Remote Bridge result application response is invalid.');
            }

            if ($outerStatus !== 200) {
                throw new \InvalidArgumentException('Remote Bridge application results require outer status 200.');
            }

            if ($application['status'] < 100 || $application['status'] > 599) {
                throw new \InvalidArgumentException('Remote Bridge application status is invalid.');
            }

            $error = null;

            if (($data['bridge_error'] ?? null) !== null) {
                if (!is_array($data['bridge_error'])) {
                    throw new \InvalidArgumentException('Remote Bridge result error is invalid.');
                }

                $error = $this->decodeBridgeError($data['bridge_error']);
            }

            if ($error === null) {
                if ($requiresQuarantine) {
                    throw new \InvalidArgumentException('Remote Bridge quarantine results require a reset or quarantine error.');
                }

                if (!$reusable) {
                    throw new \InvalidArgumentException('Remote Bridge non-quarantined results must be reusable.');
                }
            } elseif ($error['kind'] !== 'reset_or_quarantine' || $reusable || !$requiresQuarantine) {
                throw new \InvalidArgumentException('Remote Bridge application errors require quarantined reset state.');
            }

            self::assertTransportValue($application['body'] ?? null, 'applicationBody');

            return LegacyRemoteClientResult::application($requestIdentifier, $correlationIdentifier, $outerStatus, $application['status'], $this->requireHeaders($application['headers'] ?? []), $application['body'] ?? null, $reusable, $requiresQuarantine, $error);
        }

        if ($outcome === 'bridge_error') {
            $error = $data['bridge_error'] ?? null;

            if (!is_array($error)) {
                throw new \InvalidArgumentException('Remote Bridge result error is invalid.');
            }

            if (($data['application'] ?? null) !== null) {
                throw new \InvalidArgumentException('Remote Bridge error results must not include an application response.');
            }

            if (!$reusable) {
                throw new \InvalidArgumentException('Remote Bridge protocol failures must be reusable.');
            }

            if ($requiresQuarantine) {
                throw new \InvalidArgumentException('Remote Bridge protocol failures must not claim quarantine.');
            }

            $decodedError = $this->decodeBridgeError($error);

            if ($decodedError['kind'] === 'reset_or_quarantine') {
                throw new \InvalidArgumentException('Remote Bridge protocol failures must not use reset or quarantine errors.');
            }

            return LegacyRemoteClientResult::bridgeError($requestIdentifier, $correlationIdentifier, $outerStatus, $decodedError);
        }

        throw new \InvalidArgumentException('Remote Bridge result outcome is invalid.');
    }

    /** @param mixed $value */
    public static function assertTransportValue($value, string $field): void
    {
        self::assertSafeValue($value, $field, 0);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        if (strlen($json) > LegacyRemoteProtocol::MAX_BODY_BYTES) {
            throw new \LengthException('Remote Bridge JSON exceeds the maximum protocol size.');
        }

        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Remote Bridge JSON is malformed.');
        }

        if (!is_array($decoded) || $this->isList($decoded)) {
            throw new \InvalidArgumentException('Remote Bridge JSON must decode to an object.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        self::assertTransportValue($data, 'protocol');
        $this->sortRecursive($data);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($json) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Remote Bridge JSON could not be encoded.');
        }

        if (strlen($json) > LegacyRemoteProtocol::MAX_BODY_BYTES) {
            throw new \LengthException('Remote Bridge JSON exceeds the maximum protocol size.');
        }

        return $json;
    }

    /** @param mixed $value */
    private static function assertSafeValue($value, string $field, int $depth): void
    {
        if ($depth > 64) {
            throw new \InvalidArgumentException('Remote Bridge ' . $field . ' nesting is too deep.');
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }

        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new \InvalidArgumentException('Remote Bridge ' . $field . ' contains unsafe string data.');
            }

            return;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('Remote Bridge ' . $field . ' contains a non-finite float.');
            }

            return;
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException('Remote Bridge ' . $field . ' contains a transport-unsafe value.');
        }

        foreach ($value as $nested) {
            self::assertSafeValue($nested, $field, $depth + 1);
        }
    }

    /**
     * @param array<string, mixed> $error
     *
     * @return array{kind: string, code: string, message: string, retryable: bool}
     */
    private function decodeBridgeError(array $error): array
    {
        $kind = $this->requireString($error['kind'] ?? null, 'kind');

        if (!in_array($kind, $this->bridgeErrorKinds(), true)) {
            throw new \InvalidArgumentException('Remote Bridge result error kind is invalid.');
        }

        return [
            'kind' => $kind,
            'code' => $this->requireString($error['code'] ?? null, 'code'),
            'message' => $this->requireString($error['message'] ?? null, 'message'),
            'retryable' => $this->requireBool($error['retryable'] ?? null, 'retryable'),
        ];
    }

    /** @param mixed $value */
    private function requireString($value, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Remote Bridge ' . $field . ' must be a string.');
        }

        return $value;
    }

    /** @param mixed $value */
    private function requireNonBlankString($value, string $field): string
    {
        $string = $this->requireString($value, $field);

        if (trim($string) === '') {
            throw new \InvalidArgumentException('Remote Bridge ' . $field . ' must be nonblank.');
        }

        return $string;
    }

    /** @param mixed $value */
    private function requireInt($value, string $field): int
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException('Remote Bridge ' . $field . ' must be an integer.');
        }

        return $value;
    }

    /** @param mixed $value */
    private function requireBool($value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException('Remote Bridge ' . $field . ' must be a boolean.');
        }

        return $value;
    }

    /**
     * @param mixed $value
     *
     * @return array<string, list<string>>
     */
    private function requireHeaders($value): array
    {
        if ($value === []) {
            return [];
        }

        if (!is_array($value) || $this->isList($value)) {
            throw new \InvalidArgumentException('Remote Bridge headers must be an object.');
        }

        $headers = [];

        foreach ($value as $name => $values) {
            if (!is_string($name) || !is_array($values) || !$this->isList($values)) {
                throw new \InvalidArgumentException('Remote Bridge headers must contain string lists.');
            }

            $headers[strtolower($name)] = [];

            foreach ($values as $headerValue) {
                $headers[strtolower($name)][] = $this->requireString($headerValue, 'header value');
            }
        }

        ksort($headers);

        return $headers;
    }

    /**
     * @param array<string, string> $value
     *
     * @return array<string, string>
     */
    private function sortedStringMap(array $value): array
    {
        ksort($value);

        return $value;
    }

    /** @param array<mixed> $data */
    private function sortRecursive(array &$data): void
    {
        foreach (array_keys($data) as $key) {
            if (is_array($data[$key])) {
                $nested = $data[$key];
                $this->sortRecursive($nested);
                $data[$key] = $nested;
            }
        }

        if (!$this->isList($data)) {
            ksort($data);
        }
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /** @return list<string> */
    private function bridgeErrorKinds(): array
    {
        return ['configuration', 'compatibility', 'authentication', 'authorization', 'validation', 'translation', 'boot_or_readiness', 'execution', 'timeout', 'cancellation', 'transport', 'protocol', 'uncertain_outcome', 'reset_or_quarantine', 'dependency_unavailable'];
    }
}
