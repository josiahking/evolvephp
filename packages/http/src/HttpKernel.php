<?php

declare(strict_types=1);

namespace Evolve\Http;

use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Core\Execution\ExecutionScope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class HttpKernel
{
    public function __construct(
        private RequestHandlerInterface $handler,
        private ExecutionOrchestrator $executions,
    ) {}

    public function handle(ServerRequestInterface $request): ExecutionOutcome
    {
        return $this->executions->execute(
            ExecutionKind::HttpRequest,
            function (ExecutionContext $context, ExecutionScope $scope) use ($request): ResponseInterface {
                $executionRequest = $request
                    ->withAttribute(ExecutionContext::class, $context)
                    ->withAttribute(ExecutionScope::class, $scope);

                return $this->handler->handle($executionRequest);
            },
        );
    }
}
