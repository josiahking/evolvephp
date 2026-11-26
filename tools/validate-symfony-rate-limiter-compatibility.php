<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Exception\ExecutionScopeClosed;
use Evolve\Http\HttpKernel;
use Evolve\Http\Middleware\MiddlewarePipeline;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Symfony\Component\RateLimiter\LimiterStateInterface;

$autoload = getenv('EVOLVE_RATE_LIMITER_CONSUMER_AUTOLOAD');
if (!is_string($autoload) || !is_file($autoload) || basename($autoload) !== 'autoload.php'
    || basename(dirname($autoload)) !== 'vendor') {
    fwrite(STDERR, "Set EVOLVE_RATE_LIMITER_CONSUMER_AUTOLOAD to the isolated consumer vendor/autoload.php.\n");
    exit(2);
}
require $autoload;
require __DIR__ . '/fixtures/symfony-rate-limiter-consumer.php';

function checkRateLimit(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rateRequest(?string $tenant, ?string $principal): ServerRequestInterface
{
    $request = new ServerRequest('GET', '/api/items');
    if ($tenant !== null) {
        $request = $request->withAttribute('trusted_tenant_id', $tenant);
    }
    if ($principal !== null) {
        $request = $request->withAttribute('trusted_principal_id', $principal);
    }
    return $request->withHeader('X-Forwarded-For', 'untrusted.example')
        ->withHeader('Authorization', 'Bearer private-credential');
}

try {
    $version = InstalledVersions::getVersion('symfony/rate-limiter');
    checkRateLimit(is_string($version) && version_compare($version, '7.4.0', '>=')
        && version_compare($version, '7.5.0', '<'), 'Rate Limiter is outside the requested 7.4 range.');
    $worktree = realpath(dirname(__DIR__));
    $vendor = realpath(dirname($autoload));
    foreach ([ServiceRegistry::class => 'packages/core/', HttpKernel::class => 'packages/http/',
        \Evolve\Contracts\Execution\ResetParticipant::class => 'packages/contracts/'] as $symbol => $directory) {
        $file = realpath((string) (new ReflectionClass($symbol))->getFileName());
        checkRateLimit(is_string($worktree) && is_string($file)
            && str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $worktree) . '/' . $directory),
            $symbol . ' did not resolve from this worktree.');
    }
    foreach ([RateLimiterFactory::class => 'symfony/rate-limiter/', HttpFactory::class => 'guzzlehttp/psr7/'] as $symbol => $directory) {
        $file = realpath((string) (new ReflectionClass($symbol))->getFileName());
        checkRateLimit(is_string($vendor) && is_string($file)
            && str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $vendor) . '/' . $directory),
            $symbol . ' did not resolve from the isolated consumer.');
    }

    $services = new ServiceRegistry();
    registerRateLimiter($services);
    $root = $services->freeze();
    $factory = $root->get(RateLimiterFactory::class);
    checkRateLimit($factory === $root->get(RateLimiterFactory::class)
        && $root->get(StorageInterface::class) === $root->get(StorageInterface::class), 'Application storage was not shared.');
    $responses = new HttpFactory();
    $calls = 0;
    $capture = new class implements \Psr\Http\Server\MiddlewareInterface {
        /** @var list<ExecutionScope> */
        private array $scopes = [];

        /** @return list<ExecutionScope> */
        public function scopes(): array
        {
            return $this->scopes;
        }

        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $scope = $request->getAttribute(ExecutionScope::class);
            if (!$scope instanceof ExecutionScope) {
                throw new LogicException('HttpKernel omitted execution scope.');
            }
            $this->scopes[] = $scope;
            return $handler->handle($request);
        }
    };
    $terminal = new class($responses, $calls) implements RequestHandlerInterface {
        public function __construct(private HttpFactory $responses, private int &$calls) {}
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->calls++;
            return $this->responses->createResponse(200);
        }
    };
    $kernel = new HttpKernel(new MiddlewarePipeline([
        $capture,
        new ApplicationRateLimitMiddleware($factory, $responses),
    ], $terminal), new ExecutionOrchestrator($services));
    foreach ([['tenant-a', 'alice', 200], ['tenant-a', 'alice', 200], ['tenant-a', 'alice', 429],
        ['tenant-a', 'bob', 200], ['tenant-b', 'alice', 200], [null, 'alice', 503],
        ['tenant-a', '', 503]] as [$tenant, $principal, $status]) {
        $request = rateRequest($tenant, $principal);
        if ($status === 429) {
            $request = $request->withHeader('Authorization', 'Bearer changed-secret')
                ->withHeader('X-Forwarded-For', 'another-untrusted-value');
        }
        $outcome = $kernel->handle($request);
        checkRateLimit($outcome->primarySucceeded() && !$outcome->cleanupFailed(), 'Request execution or cleanup failed.');
        $response = $outcome->primaryResult();
        checkRateLimit($response instanceof ResponseInterface && $response->getStatusCode() === $status,
            'Unexpected status for sequential request.');
        if ($status === 429) {
            checkRateLimit(preg_match('/\A\d+\z/D', $response->getHeaderLine('Retry-After')) === 1,
                'Retry-After must be a nonnegative integer.');
        }
    }
    $malformed = $kernel->handle(rateRequest('tenant-a', 'alice')->withAttribute('trusted_principal_id', 42));
    checkRateLimit($malformed->primarySucceeded() && $malformed->primaryResult() instanceof ResponseInterface
        && $malformed->primaryResult()->getStatusCode() === 503, 'Malformed identity was accepted.');
    checkRateLimit($calls === 4, 'Downstream dispatch count differs from accepted requests.');
    checkRateLimit(count($capture->scopes()) === 8
        && count(array_unique(array_map('spl_object_id', $capture->scopes()))) === 8,
        'Separate requests did not receive separate execution scopes.');
    foreach ($capture->scopes() as $scope) {
        try {
            $scope->get(StorageInterface::class);
            throw new RuntimeException('Execution scope was not closed.');
        } catch (ExecutionScopeClosed) {
            // Closed after both accepted and rejected requests.
        }
    }
    $writeFactory = new RateLimiterFactory(
        ['id' => 'api-write-v1', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'],
        $root->get(StorageInterface::class),
    );
    $writeKernel = new HttpKernel(new MiddlewarePipeline([$capture,
        new ApplicationRateLimitMiddleware($writeFactory, $responses, 'api-write-v1')], $terminal),
        new ExecutionOrchestrator($services));
    foreach ([200, 429] as $status) {
        $outcome = $writeKernel->handle(rateRequest('tenant-a', 'alice'));
        checkRateLimit($outcome->primarySucceeded() && $outcome->primaryResult() instanceof ResponseInterface
            && $outcome->primaryResult()->getStatusCode() === $status, 'Policy namespace separation failed.');
    }
    checkRateLimit($calls === 5, 'Policy rejection dispatched downstream.');
    $brokenStorage = new class implements StorageInterface {
        public function save(LimiterStateInterface $limiterState): void { throw new RuntimeException('storage unavailable'); }
        public function fetch(string $limiterId): ?LimiterStateInterface { throw new RuntimeException('storage unavailable'); }
        public function delete(string $limiterId): void { throw new RuntimeException('storage unavailable'); }
    };
    $brokenFactory = new RateLimiterFactory(
        ['id' => 'broken-v1', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], $brokenStorage);
    $brokenKernel = new HttpKernel(new MiddlewarePipeline([$capture,
        new ApplicationRateLimitMiddleware($brokenFactory, $responses, 'broken-v1')], $terminal),
        new ExecutionOrchestrator($services));
    $failed = $brokenKernel->handle(rateRequest('tenant-a', 'alice'));
    checkRateLimit($failed->primarySucceeded() && $failed->primaryResult() instanceof ResponseInterface
        && $failed->primaryResult()->getStatusCode() === 503 && $calls === 5,
        'Storage failure allowed downstream execution.');
    checkRateLimit(count($capture->scopes()) === 11, 'Request scope was missing on a rejected execution.');
    foreach ($capture->scopes() as $scope) {
        try {
            $scope->get(StorageInterface::class);
            throw new RuntimeException('Execution scope remained open.');
        } catch (ExecutionScopeClosed) {
            // Every kernel call closed its scope.
        }
    }
    echo "PASS isolated Symfony Rate Limiter consumer: sequential scopes, isolation, exhaustion and fail-closed identity\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error::class . ': ' . $error->getMessage() . "\n");
    exit(1);
}
