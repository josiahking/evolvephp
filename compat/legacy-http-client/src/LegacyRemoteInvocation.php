<?php

declare(strict_types=1);

namespace Evolve\Bridge\LegacyHttp;

final class LegacyRemoteInvocation
{
    /** @var string */
    private $operation;
    /** @var string */
    private $method;
    /** @var string */
    private $target;
    /** @var array<string, list<string>> */
    private $headers;
    /** @var string */
    private $body;
    /** @var mixed */
    private $payload;
    /** @var string */
    private $requestIdentifier;
    /** @var string */
    private $correlationIdentifier;
    /** @var string */
    private $callerIdentifier;
    /** @var string|null */
    private $principalIdentifier;
    /** @var string|null */
    private $tenantIdentifier;
    /** @var string|null */
    private $locale;
    /** @var string|null */
    private $timezone;
    /** @var string|null */
    private $deadline;
    /** @var string|null */
    private $idempotencyKey;
    /** @var array<string, string> */
    private $trace;

    /**
     * @param array<string, list<string>> $headers
     * @param mixed $payload
     * @param array<string, string> $trace
     */
    public function __construct(
        string $operation,
        string $method,
        string $target,
        array $headers,
        string $body,
        $payload,
        string $requestIdentifier,
        string $correlationIdentifier,
        string $callerIdentifier,
        ?string $principalIdentifier = null,
        ?string $tenantIdentifier = null,
        ?string $locale = null,
        ?string $timezone = null,
        ?string $deadline = null,
        ?string $idempotencyKey = null,
        array $trace = []
    ) {
        self::assertNonBlank($operation, 'operation');
        self::assertNonBlank($method, 'method');
        self::assertNonBlank($target, 'target');
        self::assertNonBlank($requestIdentifier, 'requestIdentifier');
        self::assertNonBlank($correlationIdentifier, 'correlationIdentifier');
        self::assertNonBlank($callerIdentifier, 'callerIdentifier');
        self::assertOptionalNonBlank($principalIdentifier, 'principalIdentifier');
        self::assertOptionalNonBlank($tenantIdentifier, 'tenantIdentifier');
        self::assertOptionalNonBlank($locale, 'locale');
        self::assertOptionalNonBlank($timezone, 'timezone');
        self::assertDeadline($deadline);
        self::assertIdempotencyKey($idempotencyKey);
        self::assertHeaders($headers);
        self::assertTrace($trace);
        LegacyRemoteCodec::assertTransportValue($payload, 'payload');

        $this->operation = $operation;
        $this->method = strtoupper($method);
        $this->target = $target;
        $this->headers = self::normalizeHeaders($headers);
        $this->body = $body;
        $this->payload = $payload;
        $this->requestIdentifier = $requestIdentifier;
        $this->correlationIdentifier = $correlationIdentifier;
        $this->callerIdentifier = $callerIdentifier;
        $this->principalIdentifier = $principalIdentifier;
        $this->tenantIdentifier = $tenantIdentifier;
        $this->locale = $locale;
        $this->timezone = $timezone;
        $this->deadline = $deadline;
        $this->idempotencyKey = $idempotencyKey;
        $this->trace = $trace;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function target(): string
    {
        return $this->target;
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array<string, list<string>> */
    public function forwardableHeaders(): array
    {
        $headers = [];

        foreach ($this->headers as $name => $values) {
            if (LegacyRemoteProtocol::invocationHeaderIsForwardable($name)) {
                $headers[$name] = $values;
            }
        }

        ksort($headers);

        return $headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return mixed */
    public function payload()
    {
        return $this->payload;
    }

    public function requestIdentifier(): string
    {
        return $this->requestIdentifier;
    }

    public function correlationIdentifier(): string
    {
        return $this->correlationIdentifier;
    }

    public function callerIdentifier(): string
    {
        return $this->callerIdentifier;
    }

    public function principalIdentifier(): ?string
    {
        return $this->principalIdentifier;
    }

    public function tenantIdentifier(): ?string
    {
        return $this->tenantIdentifier;
    }

    public function locale(): ?string
    {
        return $this->locale;
    }

    public function timezone(): ?string
    {
        return $this->timezone;
    }

    public function deadline(): ?string
    {
        return $this->deadline;
    }

    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /** @return array<string, string> */
    public function trace(): array
    {
        return $this->trace;
    }

    public function deadlineExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->deadline === null) {
            return false;
        }

        return new \DateTimeImmutable($this->deadline) <= ($now ?: new \DateTimeImmutable());
    }

    public function withDeadline(?string $deadline): self
    {
        return new self($this->operation, $this->method, $this->target, $this->headers, $this->body, $this->payload, $this->requestIdentifier, $this->correlationIdentifier, $this->callerIdentifier, $this->principalIdentifier, $this->tenantIdentifier, $this->locale, $this->timezone, $deadline, $this->idempotencyKey, $this->trace);
    }

    private static function assertOptionalNonBlank(?string $value, string $field): void
    {
        if ($value !== null) {
            self::assertNonBlank($value, $field);
        }
    }

    private static function assertNonBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException('Remote Bridge ' . $field . ' must be nonblank.');
        }
    }

    private static function assertDeadline(?string $deadline): void
    {
        if ($deadline === null) {
            return;
        }

        $parsed = \DateTimeImmutable::createFromFormat(DATE_ATOM, $deadline);
        $errors = \DateTimeImmutable::getLastErrors();

        if (!$parsed instanceof \DateTimeImmutable || is_array($errors) && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0)) {
            throw new \InvalidArgumentException('Remote Bridge deadline must be a valid RFC3339 timestamp.');
        }
    }

    private static function assertIdempotencyKey(?string $idempotencyKey): void
    {
        if ($idempotencyKey !== null && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('Remote Bridge idempotency key is invalid.');
        }
    }

    /** @param array<string, list<string>> $headers */
    private static function assertHeaders(array $headers): void
    {
        foreach ($headers as $name => $values) {
            if (!is_string($name) || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1) {
                throw new \InvalidArgumentException('Remote Bridge header names must be valid HTTP tokens.');
            }

            if (!is_array($values) || self::isList($values) === false) {
                throw new \InvalidArgumentException('Remote Bridge headers must contain string lists.');
            }

            foreach ($values as $value) {
                if (!is_string($value) || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
                    throw new \InvalidArgumentException('Remote Bridge header values must be transport-safe strings.');
                }
            }
        }
    }

    /** @param array<string, string> $trace */
    private static function assertTrace(array $trace): void
    {
        foreach ($trace as $name => $value) {
            if (!is_string($name) || preg_match('/^[a-z0-9_.-]{1,64}$/', $name) !== 1) {
                throw new \InvalidArgumentException('Remote Bridge trace keys are invalid.');
            }

            if (!is_string($value) || $value === '' || strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException('Remote Bridge trace values must be bounded strings.');
            }
        }
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return array<string, list<string>>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            $normalized[strtolower($name)] = $values;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1) || $value === [];
    }
}
