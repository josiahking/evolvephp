<?php

declare(strict_types=1);

namespace Evolve\Http\Response;

use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOutcome;
use Evolve\Http\Exception\MethodNotAllowed;
use Evolve\Http\Exception\RouteNotFound;
use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use UnexpectedValueException;

final readonly class ExecutionOutcomeResponseResolver
{
    public function __construct(
        private ResponseFactoryInterface $responses,
    ) {}

    public function resolve(ExecutionOutcome $outcome): ResponseInterface
    {
        if ($outcome->kind() !== ExecutionKind::HttpRequest) {
            throw new InvalidArgumentException('Only HTTP request outcomes can be resolved to HTTP responses.');
        }

        if ($outcome->primarySucceeded()) {
            $response = $outcome->primaryResult();

            if (! $response instanceof ResponseInterface) {
                throw new UnexpectedValueException('Successful HTTP outcomes must contain a response.');
            }

            return $response;
        }

        $throwable = $outcome->primaryThrowableOrFail();

        if ($throwable instanceof RouteNotFound) {
            return $this->responses->createResponse(404);
        }

        if ($throwable instanceof MethodNotAllowed) {
            return $this->responses
                ->createResponse(405)
                ->withHeader('Allow', implode(', ', $throwable->allowedMethods()));
        }

        return $this->responses->createResponse(500);
    }
}
