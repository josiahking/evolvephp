<?php

declare(strict_types=1);

use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/** Application-owned, shared state. Use only for sequential requests in one process. */
function registerRateLimiter(ServiceRegistry $services): void
{
    $services->register(StorageInterface::class, ServiceLifetime::Application,
        static fn (): StorageInterface => new InMemoryStorage());
    $services->register(RateLimiterFactory::class, ServiceLifetime::Application,
        static fn (ContainerInterface $container): RateLimiterFactory => new RateLimiterFactory(
            ['id' => 'api-read-v1', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 hour'],
            $container->get(StorageInterface::class),
        ));
}

/** Trusted attributes must be set by an earlier application authentication/tenant step. */
final readonly class ApplicationRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimiterFactory $factory,
        private ResponseFactoryInterface $responses,
        private string $policy = 'api-read-v1',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $tenant = $this->trustedId($request->getAttribute('trusted_tenant_id'));
            $principal = $this->trustedId($request->getAttribute('trusted_principal_id'));
            $policy = $this->trustedId($this->policy);
            // A fixed-length digest of a typed tuple is bounded and unambiguous.
            $key = hash('sha256', json_encode([$policy, $tenant, $principal], JSON_THROW_ON_ERROR));
            $limit = $this->factory->create($key)->consume(1);
        } catch (Throwable) {
            // Identity, storage, and applicable locking failures are closed to downstream dispatch.
            return $this->responses->createResponse(503);
        }

        if (!$limit->isAccepted()) {
            $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());
            return $this->responses->createResponse(429)->withHeader('Retry-After', (string) $retryAfter);
        }

        return $handler->handle($request);
    }

    private function trustedId(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 128
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Missing or invalid trusted rate-limit identity.');
        }
        return $value;
    }
}
