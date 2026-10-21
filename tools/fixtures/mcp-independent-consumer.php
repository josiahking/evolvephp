<?php

declare(strict_types=1);

// Run only inside a disposable Composer consumer created by the release validator.
$consumer = $argv[1] ?? '';
if ($consumer === '' || !is_file($consumer . '/vendor/autoload.php')) {
    fwrite(STDERR, "Independent consumer vendor/autoload.php is required.\n");
    exit(1);
}
require $consumer . '/vendor/autoload.php';

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Mcp\Client\McpClientConfiguration;
use Evolve\Mcp\Client\McpClientException;
use Evolve\Mcp\Client\McpClientFactory;
use Evolve\Mcp\Server\McpAuthorizedServerFactory;
use Evolve\Mcp\Server\McpCapabilityExposure;
use Evolve\Mcp\Server\McpCapabilityInvoker;
use Evolve\Mcp\Server\McpCapabilityKind;
use Evolve\Mcp\Server\McpCapabilityPolicy;
use Evolve\Mcp\Server\McpPrincipal;
use Evolve\Mcp\Server\McpSecurityAuditSink;
use Evolve\Mcp\Server\McpServerBuilder;
use Evolve\Mcp\Server\McpToolDefinition;
use Evolve\Mcp\Transport\McpHttpRequestHandler;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

$packageRoot = realpath($consumer . '/vendor/evolvephp/mcp');
$loadedClass = (new ReflectionClass(McpAuthorizedServerFactory::class))->getFileName();
if ($packageRoot === false || $loadedClass === false || !str_starts_with(realpath($loadedClass), $packageRoot . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "MCP was not loaded from the independent installation.\n");
    exit(1);
}
$source = (new McpServerBuilder('consumer', '1', new class implements McpCapabilityInvoker {
    public function invoke(string $serviceId, array $arguments): mixed
    {
        return 'visible-result';
    }
}))
    ->addTool(new McpToolDefinition('visible', 'tool.visible', ['type' => 'object'], exposure: McpCapabilityExposure::RemoteApproved))
    ->addTool(new McpToolDefinition('hidden', 'tool.hidden', ['type' => 'object'], exposure: McpCapabilityExposure::RemoteApproved))
    ->build();
$registry = new ServiceRegistry();
$registry->freeze();
$audit = new class implements McpSecurityAuditSink {
    public int $decisions = 0;
    public int $denials = 0;
    public function record(McpCapabilityKind $kind, string $identity, bool $allowed, string $correlation): void
    {
        ++$this->decisions;
        if (!$allowed) {
            ++$this->denials;
        }
    }
};
$factory = new McpAuthorizedServerFactory(
    $source,
    new ExecutionOrchestrator($registry),
    static fn($scope, McpPrincipal $principal, string $serviceId, array $arguments): string => 'visible-result',
    new class implements McpCapabilityPolicy {
        public function allows(McpPrincipal $principal, McpCapabilityKind $kind, string $identity): bool
        {
            return $kind === McpCapabilityKind::Tool && $identity === 'visible';
        }
    },
    $audit,
);
// The package's client factory creates a real Mcp\Client and SDK HttpTransport.
// This in-process PSR-18 bridge exercises the supported restricted JSON HTTP path.
$httpFactory = new HttpFactory();
$handler = new McpHttpRequestHandler(
    $factory,
    $httpFactory,
    $httpFactory,
    static fn(): McpPrincipal => new McpPrincipal('consumer'),
);
$requests = 0;
$http = new class ($handler, $requests) implements ClientInterface {
    public function __construct(private McpHttpRequestHandler $handler, private int &$requests) {}
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        ++$this->requests;
        return $this->handler->handle(new ServerRequest(
            $request->getMethod(),
            $request->getUri(),
            $request->getHeaders(),
            $request->getBody(),
        ));
    }
};
$config = McpClientConfiguration::http(
    'https://localhost/mcp',
    $http,
    $httpFactory,
    $httpFactory,
    static fn(string $url): bool => $url === 'https://localhost/mcp',
);
$connection = (new McpClientFactory())->connect($config);
try {
    $catalog = $connection->discover();
    if (array_map(static fn($tool): string => $tool->name, $catalog->tools()) !== ['visible']) {
        throw new RuntimeException('Independent SDK discovery filtering failed.');
    }
    $result = $connection->callTool('visible');
    if (!isset($result->content[0]) || !$result->content[0] instanceof \Mcp\Schema\Content\TextContent || $result->content[0]->text !== 'visible-result') {
        throw new RuntimeException('Independent SDK invocation failed.');
    }
    try {
        $connection->callTool('hidden');
        throw new RuntimeException('Hidden tool unexpectedly invoked.');
    } catch (McpClientException) {
        // The server rejects a guessed hidden identity through the SDK transport.
    }
    if ($audit->denials < 1 || $audit->decisions < 3 || $requests < 3) {
        throw new RuntimeException('Independent SDK authorization or HTTP transport was not exercised.');
    }
} finally {
    $connection->close();
}
echo "Independent MCP SDK HTTP discovery, invocation and denial passed.\n";
