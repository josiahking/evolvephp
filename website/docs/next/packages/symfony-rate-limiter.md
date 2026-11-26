---
title: Symfony Rate Limiter compatibility
description: Compose upstream Symfony Rate Limiter with EvolvePHP HTTP middleware.
sidebar_position: 7
---

# Symfony Rate Limiter compatibility

Symfony Rate Limiter is an optional upstream integration. The application owns authentication, trusted tenant and principal identity, limiter policy, storage, responses and failure handling. EvolvePHP does not install or configure a limiter automatically. A limiter is not an authentication or authorization mechanism.

## Install optional dependencies

Require `evolvephp/contracts`, `evolvephp/core`, `evolvephp/http`, `symfony/rate-limiter:^7.4`, and a PSR-17 implementation such as `guzzlehttp/psr7` in the application. Use PHP 8.4 or newer for this EvolvePHP source line; review the actual resolved Symfony package requirements and lockfile before deploying. For a source preview, map the three EvolvePHP packages to `2.0.x-dev` using a Composer path repository pointing to `packages/*` in the candidate checkout. Review dependency licenses and `composer audit` for the exact resolved versions. The version certified by the isolated consumer must be reported from its installed metadata; this guide does not claim an unverified patch version.

## Register application storage and policy

The example uses sequential in-memory storage and a fixed window of two requests per hour for a single application policy. Keep storage and the factory at application lifetime so state survives separate HTTP execution scopes. Register before freezing the registry.

~~~php
<?php

use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Psr\Container\ContainerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

$services = new ServiceRegistry();
$services->register(StorageInterface::class, ServiceLifetime::Application,
    static fn (): StorageInterface => new InMemoryStorage());
$services->register(RateLimiterFactory::class, ServiceLifetime::Application,
    static fn (ContainerInterface $container): RateLimiterFactory => new RateLimiterFactory(
        ['id' => 'api-read-v1', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 hour'],
        $container->get(StorageInterface::class),
    ));
$root = $services->freeze();
~~~

## Apply the middleware

The [application middleware fixture](https://github.com/josiahking/evolvephp/blob/2.x/tools/fixtures/symfony-rate-limiter-consumer.php) implements PSR-15 and receives the upstream factory and a PSR-17 response factory. Its `process()` method reads only `trusted_tenant_id` and `trusted_principal_id` attributes set by an earlier trusted application step. It validates those values, combines them with a fixed policy identity in a typed tuple, and hashes the tuple to a fixed-length key. It never uses a raw credential or an untrusted forwarding header. Resolve identity for each request; do not keep a principal in the shared middleware, factory or storage service.

For an accepted request, the middleware calls `consume(1)` and dispatches the downstream handler once. Exhaustion returns HTTP 429 with a nonnegative integer `Retry-After` and never dispatches downstream. Missing or malformed identity, storage failures and applicable locking failures return HTTP 503 with no downstream dispatch. Keep these failures observable through the application's safe operational telemetry; never expose credentials, storage errors or stack traces in responses.

The application-owned PSR-15 middleware in the fixture can be written as a complete class:

~~~php
<?php

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

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
            $key = hash('sha256', json_encode([$policy, $tenant, $principal], JSON_THROW_ON_ERROR));
            $limit = $this->factory->create($key)->consume(1);
        } catch (Throwable) {
            return $this->responses->createResponse(503);
        }

        if (!$limit->isAccepted()) {
            $seconds = max(0, $limit->getRetryAfter()->getTimestamp() - time());
            return $this->responses->createResponse(429)->withHeader('Retry-After', (string) $seconds);
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
~~~

The trusted ID check rejects missing, empty, overlong or malformed tenant, principal and policy values. The class receives the factory and PSR-17 response factory in its constructor.

~~~php
<?php

use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Http\HttpKernel;
use Evolve\Http\Middleware\MiddlewarePipeline;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

// Authentication and trusted tenant resolution run before this middleware.
// $responses is an application-selected PSR-17 ResponseFactoryInterface.
// $terminal is the application's PSR-15 RequestHandlerInterface.
$limiter = new ApplicationRateLimitMiddleware($root->get(RateLimiterFactory::class), $responses);
$pipeline = new MiddlewarePipeline([$authenticationMiddleware, $trustedTenantMiddleware, $limiter], $terminal);
$kernel = new HttpKernel($pipeline, new ExecutionOrchestrator($services));
$outcome = $kernel->handle($request);
// Resolve the ExecutionOutcome at the HTTP boundary before emitting a response.
~~~

`HttpKernel::handle()` returns an `ExecutionOutcome`, not a response to emit directly. The kernel closes the request execution scope for accepted, rejected and failing requests. The application's HTTP boundary should check cleanup and resolve the outcome according to its response policy. The middleware's 429 and 503 responses are successful primary HTTP results; neither grants access to the downstream handler.

The policy namespace must change when two policies should have independent counters. Tenant and principal identities must come from authenticated, trusted application state; a public request header is not sufficient. The example allows bounded ASCII identifiers and hashes the ordered policy, tenant and principal tuple. If identities are unavailable, reject the request or use a separately designed anonymous policy.

## Limits and verification

`InMemoryStorage` in this example is for deterministic, sequential use in one process. It does not establish atomic updates across simultaneous requests, workers, processes or hosts. A deployment requiring concurrent or distributed enforcement needs a reviewed shared storage and locking design. Redis-backed limiting and distributed locking are outside this example. This guide does not certify every upstream limiting policy or provide DDoS protection.

To verify an isolated consumer, install the listed requirements into its own vendor directory with path repositories pointing at the exact candidate source for EvolvePHP Core, Contracts and HTTP. From the candidate repository root, set `EVOLVE_RATE_LIMITER_CONSUMER_AUTOLOAD` to that consumer's `vendor/autoload.php`, then run `php tools/validate-symfony-rate-limiter-compatibility.php`. The checker has no repository-vendor fallback and verifies source origins, actual upstream classes, accepted and rejected requests, identity and policy isolation, storage failure and execution cleanup. Record the resolved version and audit output alongside the result; an unavailable consumer environment is not a passed compatibility check.
