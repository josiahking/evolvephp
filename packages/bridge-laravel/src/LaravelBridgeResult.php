<?php

declare(strict_types=1);

namespace Evolve\Bridge\Laravel;

use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Contracts\BridgeErrorKind;
use Illuminate\Http\Response;
use LogicException;

/**
 * @experimental
 */
final readonly class LaravelBridgeResult
{
    public function __construct(
        private ?Response $response,
        private ?BridgeError $error,
        private bool $requiresQuarantine,
    ) {
        if ($response === null && $error === null) {
            throw new LogicException('Laravel bridge results require either a response or an error.');
        }

        if ($requiresQuarantine && $error === null) {
            throw new LogicException('Laravel bridge quarantine requires an error.');
        }

        if ($requiresQuarantine && $error->kind() !== BridgeErrorKind::ResetOrQuarantine) {
            throw new LogicException('Laravel bridge quarantine requires a reset or quarantine error.');
        }

        if ($error?->kind() === BridgeErrorKind::ResetOrQuarantine && ! $requiresQuarantine) {
            throw new LogicException('Laravel bridge reset or quarantine errors require quarantine.');
        }

        if ($response !== null && $error !== null && ! $requiresQuarantine) {
            throw new LogicException('Reusable Laravel bridge responses must not include an error.');
        }
    }

    public function response(): ?Response
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
