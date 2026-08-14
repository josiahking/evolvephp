<?php

declare(strict_types=1);

namespace Evolve\Http\Health;

use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class ReadinessHandler implements RequestHandlerInterface
{
    /**
     * @var list<ReadinessCheck>
     */
    private array $checks;

    /**
     * @param iterable<mixed, mixed> $checks
     */
    public function __construct(
        private ResponseFactoryInterface $responses,
        iterable $checks = [],
    ) {
        $validatedChecks = [];

        foreach ($checks as $check) {
            if (! $check instanceof ReadinessCheck) {
                throw new InvalidArgumentException('Readiness checks must implement ReadinessCheck.');
            }

            $validatedChecks[] = $check;
        }

        $this->checks = $validatedChecks;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        foreach ($this->checks as $check) {
            try {
                if (! $check->isReady()) {
                    return $this->responses->createResponse(503);
                }
            } catch (Throwable) {
                return $this->responses->createResponse(503);
            }
        }

        return $this->responses->createResponse(200);
    }
}
