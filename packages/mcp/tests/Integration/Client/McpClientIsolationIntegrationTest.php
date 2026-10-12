<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Integration\Client;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Mcp\Client\McpClientConfiguration;
use Evolve\Mcp\Client\McpClientException;
use Evolve\Mcp\Client\McpClientFactory;
use Evolve\Mcp\Client\McpClientFailureCategory;
use Evolve\Mcp\Client\ScopedMcpClient;
use Evolve\Secret\Contracts\SecretName;
use Evolve\Secret\Contracts\SecretResolver;
use Evolve\Secret\Contracts\SecretValue;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class McpClientIsolationIntegrationTest extends TestCase
{
    public function test_each_run_resolves_secrets_and_owns_an_independent_execution_scope(): void
    {
        $factory = new HttpFactory();
        $http = $this->createStub(ClientInterface::class);
        $http->method('sendRequest')->willReturnCallback(static function (RequestInterface $request) use ($factory): ResponseInterface {
            $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => ['supportedVersions' => ['2026-07-28']],
                ], JSON_THROW_ON_ERROR)));
        });
        $resolver = $this->createMock(SecretResolver::class);
        $resolver->expects(self::exactly(2))->method('resolve')->willReturn(new SecretValue('per-run-token'));
        $configuration = McpClientConfiguration::http(
            'https://localhost/mcp',
            $http,
            $factory,
            $factory,
            static fn(): bool => true,
            new SecretName('mcp.token'),
        );
        $registry = new ServiceRegistry();
        $registry->freeze();
        $scoped = new ScopedMcpClient(new ExecutionOrchestrator($registry), new McpClientFactory(), $resolver);
        $scopes = [];
        $first = $scoped->run($configuration, static function ($connection, ExecutionScope $scope) use (&$scopes): string {
            $scopes[] = $scope;
            return 'first';
        });
        $second = $scoped->run($configuration, static function ($connection, ExecutionScope $scope) use (&$scopes): string {
            $scopes[] = $scope;
            return 'second';
        });

        self::assertSame(['first', 'second'], [$first, $second]);
        self::assertNotSame($scopes[0], $scopes[1]);
        self::assertNull($scoped->quarantineFailure());
    }

    public function test_connection_operation_failure_poisoning_and_cleanup_failure_are_inspectable(): void
    {
        $factory = new HttpFactory();
        $http = $this->createStub(ClientInterface::class);
        $configuration = McpClientConfiguration::http('https://localhost/mcp', $http, $factory, $factory, static fn(): bool => true);
        $sdk = $this->createMock(\Mcp\Client::class);
        $sdk->expects(self::once())->method('callTool')->willThrowException(new \RuntimeException('remote raw detail'));
        $sdk->expects(self::once())->method('disconnect')->willThrowException(new \RuntimeException('cleanup raw detail'));
        $connection = new \Evolve\Mcp\Client\McpClientConnection($sdk, $configuration);

        try {
            $connection->callTool('echo');
            self::fail('Expected operation failure.');
        } catch (McpClientException $exception) {
            self::assertSame(McpClientFailureCategory::Operation, $exception->category);
            self::assertStringNotContainsString('raw', $exception->getMessage());
        }
        self::assertFalse($connection->isReusable());
        try {
            $connection->callTool('echo');
            self::fail('Expected poisoned connection to refuse reuse.');
        } catch (McpClientException $exception) {
            self::assertSame(McpClientFailureCategory::Unusable, $exception->category);
        }
        try {
            $connection->close();
            self::fail('Expected cleanup failure.');
        } catch (McpClientException $exception) {
            self::assertSame(McpClientFailureCategory::Cleanup, $exception->category);
            self::assertSame($exception, $connection->quarantineFailure());
        }
    }
    public function test_primary_failure_and_scope_cleanup_failure_are_reported_separately_and_poison_worker(): void
    {
        $factory = new HttpFactory();
        $http = $this->createStub(ClientInterface::class);
        $http->method('sendRequest')->willReturnCallback(static function (RequestInterface $request) use ($factory): ResponseInterface {
            $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return $factory->createResponse(200)->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode([
                    'jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => [],
                ], JSON_THROW_ON_ERROR)));
        });
        $configuration = McpClientConfiguration::http('https://localhost/mcp', $http, $factory, $factory, static fn(): bool => true);
        $registry = new ServiceRegistry();
        $registry->freeze();
        $scoped = new ScopedMcpClient(new ExecutionOrchestrator($registry), new McpClientFactory());
        try {
            $scoped->run($configuration, static function ($connection, ExecutionScope $scope): never {
                $scope->registerResetParticipant('broken', new class implements ResetParticipant {
                    public function reset(): void
                    {
                        throw new \RuntimeException('cleanup detail');
                    }
                });
                throw new \RuntimeException('primary detail');
            });
            self::fail('Expected terminal failure.');
        } catch (McpClientException $exception) {
            self::assertSame(McpClientFailureCategory::Operation, $exception->category);
            self::assertSame(McpClientFailureCategory::Cleanup, $exception->cleanupCategory);
            self::assertStringNotContainsString('detail', $exception->getMessage());
        }

        self::assertNotNull($scoped->quarantineFailure());
        $this->expectException(McpClientException::class);
        $scoped->run($configuration, static fn(): null => null);
    }
}
