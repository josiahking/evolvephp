<?php

declare(strict_types=1);

namespace Evolve\Bridge\Remote;

use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Contracts\BridgeErrorKind;
use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class RemoteBridgeResult
{
    /**
     * @param array<string, list<string>> $applicationHeaders
     */
    private function __construct(
        private string $outcome,
        private string $requestIdentifier,
        private string $correlationIdentifier,
        private int $outerStatus,
        private ?int $applicationStatus,
        private array $applicationHeaders,
        private mixed $applicationBody,
        private ?BridgeError $bridgeError,
        private bool $reusable,
        private bool $requiresQuarantine,
    ) {
        if (!in_array($outcome, ['application', 'bridge_error'], true)) {
            throw new InvalidArgumentException('Remote Bridge result outcome is invalid.');
        }

        if (trim($requestIdentifier) === '' || trim($correlationIdentifier) === '') {
            throw new InvalidArgumentException('Remote Bridge result identifiers must be nonblank.');
        }

        if ($outerStatus < 100 || $outerStatus > 599) {
            throw new InvalidArgumentException('Remote Bridge outer status is invalid.');
        }

        if ($outcome === 'application' && $applicationStatus === null) {
            throw new InvalidArgumentException('Remote Bridge application results require an application response.');
        }

        if ($outcome === 'bridge_error' && ($applicationStatus !== null || $applicationHeaders !== [] || $applicationBody !== null || $bridgeError === null)) {
            throw new InvalidArgumentException('Remote Bridge error results must not include application response fields.');
        }

        if ($outcome === 'bridge_error' && !$reusable) {
            throw new InvalidArgumentException('Remote Bridge protocol failures must be reusable.');
        }

        if ($outcome === 'bridge_error' && $requiresQuarantine) {
            throw new InvalidArgumentException('Remote Bridge protocol failures must not claim quarantine.');
        }

        if ($outcome === 'bridge_error' && $bridgeError->kind() === BridgeErrorKind::ResetOrQuarantine) {
            throw new InvalidArgumentException('Remote Bridge protocol failures must not use reset or quarantine errors.');
        }

        if ($outcome === 'application' && $bridgeError === null && !$reusable) {
            throw new InvalidArgumentException('Remote Bridge non-quarantined results must be reusable.');
        }

        if ($outcome === 'application' && $bridgeError !== null && ($bridgeError->kind() !== BridgeErrorKind::ResetOrQuarantine || $reusable || !$requiresQuarantine)) {
            throw new InvalidArgumentException('Remote Bridge application errors require quarantined reset state.');
        }

        if ($requiresQuarantine && ($reusable || $bridgeError?->kind() !== BridgeErrorKind::ResetOrQuarantine)) {
            throw new InvalidArgumentException('Remote Bridge quarantine results require a reset or quarantine error.');
        }

        if ($bridgeError?->kind() === BridgeErrorKind::ResetOrQuarantine && !$requiresQuarantine) {
            throw new InvalidArgumentException('Remote Bridge reset or quarantine errors require quarantined state.');
        }

        if ($applicationStatus !== null && ($applicationStatus < 100 || $applicationStatus > 599)) {
            throw new InvalidArgumentException('Remote Bridge application status is invalid.');
        }

        RemoteBridgeCodec::assertTransportValue($applicationBody, 'applicationBody');
    }

    /**
     * @param array<string, list<string>> $headers
     */
    public static function applicationResponse(
        string $requestIdentifier,
        string $correlationIdentifier,
        int $applicationStatus,
        array $headers,
        mixed $body,
        ?BridgeError $error = null,
        bool $reusable = true,
        bool $requiresQuarantine = false,
    ): self {
        return new self('application', $requestIdentifier, $correlationIdentifier, 200, $applicationStatus, $headers, $body, $error, $reusable, $requiresQuarantine);
    }

    public static function error(
        string $requestIdentifier,
        string $correlationIdentifier,
        int $outerStatus,
        BridgeError $error,
    ): self {
        return new self('bridge_error', $requestIdentifier, $correlationIdentifier, $outerStatus, null, [], null, $error, true, false);
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function requestIdentifier(): string
    {
        return $this->requestIdentifier;
    }

    public function correlationIdentifier(): string
    {
        return $this->correlationIdentifier;
    }

    public function outerStatus(): int
    {
        return $this->outerStatus;
    }

    public function applicationStatus(): ?int
    {
        return $this->applicationStatus;
    }

    /**
     * @return array<string, list<string>>
     */
    public function applicationHeaders(): array
    {
        return $this->applicationHeaders;
    }

    public function applicationBody(): mixed
    {
        return $this->applicationBody;
    }

    public function bridgeError(): ?BridgeError
    {
        return $this->bridgeError;
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
