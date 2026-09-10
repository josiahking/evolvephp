<?php

declare(strict_types=1);

namespace Evolve\Bridge\Psr;

use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Contracts\BridgeErrorKind;
use LogicException;
use Psr\Http\Message\ResponseInterface;

/**
 * @experimental
 */
final readonly class EmbeddedBridgeResult
{
    public function __construct(
        private ?ResponseInterface $response,
        private ?BridgeError $error,
        private bool $requiresQuarantine,
    ) {
        if ($response === null && $error === null) {
            throw new LogicException('Embedded bridge results require either a response or an error.');
        }

        if ($requiresQuarantine && $response === null) {
            throw new LogicException('Embedded bridge quarantine requires a response.');
        }

        if ($requiresQuarantine && $error === null) {
            throw new LogicException('Embedded bridge quarantine requires an error.');
        }

        if ($requiresQuarantine && $error->kind() !== BridgeErrorKind::ResetOrQuarantine) {
            throw new LogicException('Embedded bridge quarantine requires a reset or quarantine error.');
        }

        if ($error?->kind() === BridgeErrorKind::ResetOrQuarantine && ! $requiresQuarantine) {
            throw new LogicException('Embedded bridge reset or quarantine errors require quarantine.');
        }

        if ($response !== null && $error !== null && ! $requiresQuarantine) {
            throw new LogicException('Embedded bridge reusable responses must not include an error.');
        }
    }

    public function response(): ?ResponseInterface
    {
        return $this->response;
    }

    public function error(): ?BridgeError
    {
        return $this->error;
    }

    public function isReusable(): bool
    {
        return ! $this->requiresQuarantine;
    }

    public function requiresQuarantine(): bool
    {
        return $this->requiresQuarantine;
    }
}
