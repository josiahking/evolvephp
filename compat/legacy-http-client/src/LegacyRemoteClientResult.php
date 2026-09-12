<?php

declare(strict_types=1);

namespace Evolve\Bridge\LegacyHttp;

final class LegacyRemoteClientResult extends \RuntimeException
{
    /** @var string */
    private $state;
    /** @var string */
    private $outcome;
    /** @var string|null */
    private $requestIdentifier;
    /** @var string|null */
    private $correlationIdentifier;
    /** @var int|null */
    private $outerStatus;
    /** @var int|null */
    private $applicationStatus;
    /** @var array<string, list<string>> */
    private $applicationHeaders;
    /** @var mixed */
    private $applicationBody;
    /** @var string|null */
    private $bridgeErrorKind;
    /** @var string|null */
    private $bridgeErrorCode;
    /** @var string|null */
    private $bridgeErrorMessage;
    /** @var bool|null */
    private $bridgeErrorRetryable;
    /** @var bool */
    private $reusable;
    /** @var bool */
    private $requiresQuarantine;

    /**
     * @param array<string, list<string>> $applicationHeaders
     * @param mixed $applicationBody
     */
    private function __construct(
        string $state,
        string $outcome,
        ?string $requestIdentifier,
        ?string $correlationIdentifier,
        ?int $outerStatus,
        ?int $applicationStatus,
        array $applicationHeaders,
        $applicationBody,
        ?string $bridgeErrorKind,
        ?string $bridgeErrorCode,
        ?string $bridgeErrorMessage,
        ?bool $bridgeErrorRetryable,
        bool $reusable,
        bool $requiresQuarantine
    ) {
        if (!in_array($outcome, ['application', 'bridge_error', 'failure'], true)) {
            throw new \InvalidArgumentException('Remote Bridge result outcome is invalid.');
        }

        if (($requestIdentifier !== null && trim($requestIdentifier) === '') || ($correlationIdentifier !== null && trim($correlationIdentifier) === '')) {
            throw new \InvalidArgumentException('Remote Bridge result identifiers must be nonblank.');
        }

        if ($outerStatus !== null && ($outerStatus < 100 || $outerStatus > 599)) {
            throw new \InvalidArgumentException('Remote Bridge outer status is invalid.');
        }

        if ($applicationStatus !== null && ($applicationStatus < 100 || $applicationStatus > 599)) {
            throw new \InvalidArgumentException('Remote Bridge application status is invalid.');
        }

        if ($outcome === 'bridge_error' && $bridgeErrorKind === 'reset_or_quarantine') {
            throw new \InvalidArgumentException('Remote Bridge protocol failures must not use reset or quarantine errors.');
        }

        if ($requiresQuarantine && ($reusable || $bridgeErrorKind !== 'reset_or_quarantine')) {
            throw new \InvalidArgumentException('Remote Bridge quarantine results require a reset or quarantine error.');
        }

        if ($bridgeErrorKind === 'reset_or_quarantine' && !$requiresQuarantine) {
            throw new \InvalidArgumentException('Remote Bridge reset or quarantine errors require quarantined state.');
        }

        parent::__construct($bridgeErrorMessage ?: $state);

        $this->state = $state;
        $this->outcome = $outcome;
        $this->requestIdentifier = $requestIdentifier;
        $this->correlationIdentifier = $correlationIdentifier;
        $this->outerStatus = $outerStatus;
        $this->applicationStatus = $applicationStatus;
        $this->applicationHeaders = $applicationHeaders;
        $this->applicationBody = $applicationBody;
        $this->bridgeErrorKind = $bridgeErrorKind;
        $this->bridgeErrorCode = $bridgeErrorCode;
        $this->bridgeErrorMessage = $bridgeErrorMessage;
        $this->bridgeErrorRetryable = $bridgeErrorRetryable;
        $this->reusable = $reusable;
        $this->requiresQuarantine = $requiresQuarantine;
    }

    /**
     * @param array<string, list<string>> $headers
     * @param mixed $body
     * @param array{kind: string, code: string, message: string, retryable: bool}|null $bridgeError
     */
    public static function application(string $requestIdentifier, string $correlationIdentifier, int $outerStatus, int $applicationStatus, array $headers, $body, bool $reusable, bool $requiresQuarantine, ?array $bridgeError): self
    {
        return new self('received', 'application', $requestIdentifier, $correlationIdentifier, $outerStatus, $applicationStatus, $headers, $body, $bridgeError['kind'] ?? null, $bridgeError['code'] ?? null, $bridgeError['message'] ?? null, $bridgeError['retryable'] ?? null, $reusable, $requiresQuarantine);
    }

    /**
     * @param array{kind: string, code: string, message: string, retryable: bool} $bridgeError
     */
    public static function bridgeError(string $requestIdentifier, string $correlationIdentifier, int $outerStatus, array $bridgeError): self
    {
        return new self('received', 'bridge_error', $requestIdentifier, $correlationIdentifier, $outerStatus, null, [], null, $bridgeError['kind'], $bridgeError['code'], $bridgeError['message'], $bridgeError['retryable'], true, false);
    }

    public static function failure(string $kind, string $code, string $message): self
    {
        return new self('failure', 'failure', null, null, null, null, [], null, $kind, $code, $message, false, true, false);
    }

    public static function timeoutFailure(): self
    {
        return self::failure('timeout', 'remote_bridge_timeout', 'Remote Bridge transport timed out.');
    }

    public static function uncertainTransportFailure(): self
    {
        return self::failure('uncertain_outcome', 'remote_bridge_outcome_uncertain', 'Remote Bridge transport failed without a response; the outcome is uncertain.');
    }

    public function received(): bool
    {
        return $this->state === 'received';
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function requestIdentifier(): ?string
    {
        return $this->requestIdentifier;
    }

    public function correlationIdentifier(): ?string
    {
        return $this->correlationIdentifier;
    }

    public function outerStatus(): ?int
    {
        return $this->outerStatus;
    }

    public function applicationStatus(): ?int
    {
        return $this->applicationStatus;
    }

    /** @return array<string, list<string>> */
    public function applicationHeaders(): array
    {
        return $this->applicationHeaders;
    }

    /** @return mixed */
    public function applicationBody()
    {
        return $this->applicationBody;
    }

    public function failureKind(): ?string
    {
        return $this->bridgeErrorKind;
    }

    public function failureCode(): ?string
    {
        return $this->bridgeErrorCode;
    }

    public function bridgeErrorKind(): ?string
    {
        return $this->bridgeErrorKind;
    }

    public function bridgeErrorCode(): ?string
    {
        return $this->bridgeErrorCode;
    }

    public function bridgeErrorMessage(): ?string
    {
        return $this->bridgeErrorMessage;
    }

    public function bridgeErrorRetryable(): ?bool
    {
        return $this->bridgeErrorRetryable;
    }

    public function isReusable(): bool
    {
        return $this->reusable;
    }

    public function requiresQuarantine(): bool
    {
        return $this->requiresQuarantine;
    }
}
