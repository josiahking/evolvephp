<?php

declare(strict_types=1);

namespace Evolve\Bridge\Remote;

use DateTimeImmutable;
use Evolve\Bridge\Contracts\BridgeContext;
use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class RemoteBridgeInvocation
{
    /**
     * @param array<string, list<string>> $headers
     * @param array<string, string> $trace
     */
    public function __construct(
        private string $operation,
        private string $method,
        private string $target,
        private array $headers,
        private string $body,
        private mixed $payload,
        private string $requestIdentifier,
        private string $correlationIdentifier,
        private string $callerIdentifier,
        private ?string $principalIdentifier = null,
        private ?string $tenantIdentifier = null,
        private ?string $locale = null,
        private ?string $timezone = null,
        private ?string $deadline = null,
        private ?string $idempotencyKey = null,
        private array $trace = [],
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
        RemoteBridgeCodec::assertTransportValue($payload, 'payload');
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function method(): string
    {
        return strtoupper($this->method);
    }

    public function target(): string
    {
        return $this->target;
    }

    /**
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return array<string, list<string>>
     */
    public function forwardableHeaders(): array
    {
        $headers = [];

        foreach ($this->headers as $name => $values) {
            $normalized = strtolower($name);

            if (RemoteBridgeProtocol::headerIsForwardable($normalized)) {
                $headers[$normalized] = $values;
            }
        }

        ksort($headers);

        return $headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function payload(): mixed
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

    /**
     * @return array<string, string>
     */
    public function trace(): array
    {
        return $this->trace;
    }

    public function context(): BridgeContext
    {
        return new BridgeContext(
            $this->requestIdentifier,
            $this->correlationIdentifier,
            $this->principalIdentifier,
            $this->tenantIdentifier,
            $this->locale,
            $this->timezone,
        );
    }

    public function deadlineExpired(DateTimeImmutable $now): bool
    {
        if ($this->deadline === null) {
            return false;
        }

        return new DateTimeImmutable($this->deadline) <= $now;
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
            throw new InvalidArgumentException('Remote Bridge ' . $field . ' must be nonblank.');
        }
    }

    private static function assertDeadline(?string $deadline): void
    {
        if ($deadline === null) {
            return;
        }

        $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $deadline);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$parsed instanceof DateTimeImmutable || is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException('Remote Bridge deadline must be a valid RFC3339 timestamp.');
        }
    }

    private static function assertIdempotencyKey(?string $idempotencyKey): void
    {
        if ($idempotencyKey === null) {
            return;
        }

        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $idempotencyKey)) {
            throw new InvalidArgumentException('Remote Bridge idempotency key is invalid.');
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function assertHeaders(array $headers): void
    {
        foreach ($headers as $name => $values) {
            if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
                throw new InvalidArgumentException('Remote Bridge header names must be valid HTTP tokens.');
            }

            foreach ($values as $value) {
                if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
                    throw new InvalidArgumentException('Remote Bridge header values must be transport-safe strings.');
                }
            }
        }
    }

    /**
     * @param array<string, string> $trace
     */
    private static function assertTrace(array $trace): void
    {
        foreach ($trace as $name => $value) {
            if (!preg_match('/^[a-z0-9_.-]{1,64}$/', $name)) {
                throw new InvalidArgumentException('Remote Bridge trace keys are invalid.');
            }

            if ($value === '' || strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('Remote Bridge trace values must be bounded strings.');
            }
        }
    }
}
