<?php

declare(strict_types=1);

namespace Evolve\Bridge\Psr;

use Evolve\Bridge\Contracts\BridgeContext;
use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Contracts\BridgeErrorKind;
use Evolve\Http\Health\ReadinessCheck;
use Evolve\Http\HttpKernel;
use Evolve\Http\Response\ExecutionOutcomeResponseResolver;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @experimental
 */
final readonly class EmbeddedBridgeAdapter
{
    public function __construct(
        private HttpKernel $kernel,
        private ExecutionOutcomeResponseResolver $responses,
        private ReadinessCheck $readiness,
    ) {}

    public function invoke(
        ServerRequestInterface $request,
        BridgeContext $context,
    ): EmbeddedBridgeResult {
        if (! $this->readiness->isReady()) {
            return new EmbeddedBridgeResult(
                null,
                new BridgeError(
                    BridgeErrorKind::BootOrReadiness,
                    'embedded_not_ready',
                    'Embedded Evolve application is not ready for delegated HTTP execution.',
                    false,
                ),
                false,
            );
        }

        $executionRequest = $request->withAttribute(BridgeContext::class, $context);
        $outcome = $this->kernel->handle($executionRequest);
        $response = $this->responses->resolve($outcome);

        if ($outcome->requiresQuarantine()) {
            return new EmbeddedBridgeResult(
                $response,
                new BridgeError(
                    BridgeErrorKind::ResetOrQuarantine,
                    'embedded_process_quarantined',
                    'Embedded Evolve execution completed, but cleanup did not prove process reuse safe.',
                    false,
                ),
                true,
            );
        }

        return new EmbeddedBridgeResult($response, null, false);
    }
}
