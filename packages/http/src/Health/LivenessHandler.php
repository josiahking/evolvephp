<?php

declare(strict_types=1);

namespace Evolve\Http\Health;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class LivenessHandler implements RequestHandlerInterface
{
    public function __construct(
        private ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->createResponse(200);
    }
}
