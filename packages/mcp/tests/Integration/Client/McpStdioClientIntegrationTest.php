<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Integration\Client;

use Evolve\Mcp\Client\McpClientConfiguration;
use Evolve\Mcp\Client\McpClientException;
use Evolve\Mcp\Client\McpClientFactory;
use Evolve\Mcp\Client\McpClientFailureCategory;
use Evolve\Secret\Contracts\SecretName;
use Mcp\Client;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use PHPUnit\Framework\TestCase;

final class McpStdioClientIntegrationTest extends TestCase
{
    public function test_non_linux_stdio_is_refused_before_secret_resolution_or_child_launch(): void
    {
        if (PHP_OS_FAMILY === 'Linux') {
            self::markTestSkipped('Native non-Linux guard runs on non-Linux PHP hosts.');
        }

        $marker = tempnam(sys_get_temp_dir(), 'mcp-guard-');
        self::assertIsString($marker);
        unlink($marker);
        $configuration = McpClientConfiguration::stdio(
            PHP_BINARY,
            ['-r', 'file_put_contents($argv[1], "started");', $marker],
            dirname(PHP_BINARY),
            [],
            static fn(): bool => true,
            ['MCP_TOKEN' => new SecretName('mcp.token')],
        );

        try {
            (new McpClientFactory())->connect($configuration);
            self::fail('Expected platform refusal.');
        } catch (McpClientException $exception) {
            self::assertSame(McpClientFailureCategory::Configuration, $exception->category);
            self::assertFileDoesNotExist($marker);
        }
    }

    public function test_linux_sdk_handshake_discovery_invocation_environment_and_disconnect(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux SDK STDIO lifecycle.');
        }

        $pidFile = tempnam(sys_get_temp_dir(), 'mcp-pid-');
        self::assertIsString($pidFile);
        $previous = getenv('EVOLVE_MCP_AMBIENT_SECRET');
        putenv('EVOLVE_MCP_AMBIENT_SECRET=must-not-leak');

        try {
            $configuration = McpClientConfiguration::stdio(
                PHP_BINARY,
                ['-r', self::sdkServerScript(), $pidFile],
                dirname(__DIR__, 5),
                ['MCP_ALLOWED' => 'explicit'],
                static fn(): bool => true,
                initTimeoutSeconds: 5,
            );
            $connection = (new McpClientFactory())->connect($configuration);
            try {
                $client = (new \ReflectionProperty($connection, 'client'))->getValue($connection);
                self::assertInstanceOf(Client::class, $client);
                self::assertTrue($client->isConnected());
                self::assertSame(ProtocolVersion::V2025_06_18, $client->getProtocolVersion());
                self::assertSame('stdio-fixture', $client->getServerInfo()?->name);

                $catalog = $connection->discover();
                self::assertSame('environment', $catalog->tools()[0]->name);
                self::assertSame('example://document', $catalog->resources()[0]->uri);
                self::assertSame('greet', $catalog->prompts()[0]->name);
                $content = $connection->callTool('environment')->content[0];
                self::assertInstanceOf(TextContent::class, $content);
                self::assertSame('explicit:isolated', $content->text);
            } finally {
                $connection->close();
            }

            self::assertFalse($connection->isReusable());
            $pid = (int) file_get_contents($pidFile);
            self::assertGreaterThan(0, $pid);
            $deadline = microtime(true) + 2;
            while (posix_kill($pid, 0) && microtime(true) < $deadline) {
                usleep(20000);
            }
            self::assertFalse(posix_kill($pid, 0), 'SDK child must exit after disconnect.');
        } finally {
            $previous === false ? putenv('EVOLVE_MCP_AMBIENT_SECRET') : putenv('EVOLVE_MCP_AMBIENT_SECRET=' . $previous);
            unlink($pidFile);
        }
    }

    public function test_linux_empty_child_environment_does_not_inherit_parent_credentials(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux SDK STDIO environment isolation.');
        }

        $marker = tempnam(sys_get_temp_dir(), 'mcp-env-');
        self::assertIsString($marker);
        $previous = getenv('EVOLVE_MCP_AMBIENT_SECRET');
        putenv('EVOLVE_MCP_AMBIENT_SECRET=must-not-leak');

        try {
            $configuration = McpClientConfiguration::stdio(
                PHP_BINARY,
                ['-r', "file_put_contents(\$argv[1], getenv('EVOLVE_MCP_AMBIENT_SECRET') === false ? 'isolated' : 'leaked'); exit(1);", $marker],
                dirname(PHP_BINARY),
                [],
                static fn(): bool => true,
                initTimeoutSeconds: 1,
            );

            try {
                (new McpClientFactory())->connect($configuration);
                self::fail('Expected child initialization failure.');
            } catch (McpClientException $exception) {
                self::assertSame(McpClientFailureCategory::Connection, $exception->category);
                self::assertSame('isolated', file_get_contents($marker));
            }
        } finally {
            $previous === false ? putenv('EVOLVE_MCP_AMBIENT_SECRET') : putenv('EVOLVE_MCP_AMBIENT_SECRET=' . $previous);
            unlink($marker);
        }
    }

    public function test_missing_named_environment_secret_prevents_child_start(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux secret-resolution ordering; non-Linux refuses STDIO first.');
        }

        $configuration = McpClientConfiguration::stdio(
            PHP_BINARY,
            ['-r', 'exit(1);'],
            dirname(PHP_BINARY),
            [],
            static fn(): bool => true,
            ['MCP_TOKEN' => new SecretName('mcp.token')],
        );

        try {
            (new McpClientFactory())->connect($configuration);
            self::fail('Expected secret failure before starting a child.');
        } catch (McpClientException $exception) {
            self::assertSame(McpClientFailureCategory::Secret, $exception->category);
        }
    }

    public function test_trusted_child_that_exits_before_initialization_fails_safely(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux SDK STDIO lifecycle.');
        }

        $configuration = McpClientConfiguration::stdio(
            PHP_BINARY,
            ['-r', 'exit(1);'],
            dirname(PHP_BINARY),
            [],
            static fn(): bool => true,
            initTimeoutSeconds: 1,
        );

        try {
            (new McpClientFactory())->connect($configuration);
            self::fail('Expected child startup or initialization failure.');
        } catch (McpClientException $exception) {
            self::assertSame(McpClientFailureCategory::Connection, $exception->category);
            self::assertNull($exception->getPrevious());
        }
    }

    public function test_linux_unresponsive_child_times_out_and_fails_safely(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux SDK STDIO lifecycle.');
        }

        $configuration = McpClientConfiguration::stdio(
            PHP_BINARY,
            ['-r', 'sleep(3);'],
            dirname(PHP_BINARY),
            [],
            static fn(): bool => true,
            initTimeoutSeconds: 1,
        );

        try {
            (new McpClientFactory())->connect($configuration);
            self::fail('Expected bounded initialization failure.');
        } catch (McpClientException $exception) {
            self::assertSame(McpClientFailureCategory::Connection, $exception->category);
            self::assertNull($exception->getPrevious());
        }
    }

    public function test_linux_malformed_child_response_fails_safely(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux SDK STDIO lifecycle.');
        }

        $configuration = McpClientConfiguration::stdio(
            PHP_BINARY,
            ['-r', 'fwrite(STDOUT, "{broken\n"); fflush(STDOUT); sleep(2);'],
            dirname(PHP_BINARY),
            [],
            static fn(): bool => true,
            initTimeoutSeconds: 1,
        );

        try {
            (new McpClientFactory())->connect($configuration);
            self::fail('Expected malformed-response failure.');
        } catch (McpClientException $exception) {
            self::assertSame(McpClientFailureCategory::Connection, $exception->category);
            self::assertNull($exception->getPrevious());
        }
    }

    public function test_linux_sdk_operation_timeout_and_error_propagation_poison_connection(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux SDK STDIO lifecycle.');
        }

        $pidFile = tempnam(sys_get_temp_dir(), 'mcp-pid-');
        self::assertIsString($pidFile);
        try {
            $configuration = McpClientConfiguration::stdio(
                PHP_BINARY,
                ['-r', self::sdkServerScript(), $pidFile],
                dirname(__DIR__, 5),
                ['MCP_ALLOWED' => 'explicit'],
                static fn(): bool => true,
                initTimeoutSeconds: 5,
                requestTimeoutSeconds: 1,
            );
            $connection = (new McpClientFactory())->connect($configuration);
            try {
                $start = microtime(true);
                try {
                    $connection->callTool('slow');
                    self::fail('Expected SDK request timeout.');
                } catch (McpClientException $exception) {
                    self::assertSame(McpClientFailureCategory::Operation, $exception->category);
                    self::assertFalse($connection->isReusable());
                    self::assertNull($exception->getPrevious());
                    self::assertLessThan(5, microtime(true) - $start);
                }
            } finally {
                $connection->close();
            }
        } finally {
            unlink($pidFile);
        }
    }

    public function test_linux_sdk_remote_error_is_categorized_and_connection_is_poisoned(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux SDK STDIO lifecycle.');
        }

        $pidFile = tempnam(sys_get_temp_dir(), 'mcp-pid-');
        self::assertIsString($pidFile);
        try {
            $configuration = McpClientConfiguration::stdio(
                PHP_BINARY,
                ['-r', self::sdkServerScript(), $pidFile],
                dirname(__DIR__, 5),
                ['MCP_ALLOWED' => 'explicit'],
                static fn(): bool => true,
            );
            $connection = (new McpClientFactory())->connect($configuration);
            try {
                try {
                    $connection->callTool('not-registered');
                    self::fail('Expected remote tool error.');
                } catch (McpClientException $exception) {
                    self::assertSame(McpClientFailureCategory::Operation, $exception->category);
                    self::assertFalse($connection->isReusable());
                    self::assertNull($exception->getPrevious());
                }
            } finally {
                $connection->close();
            }
        } finally {
            unlink($pidFile);
        }
    }

    public function test_stdio_cleanup_failure_quarantines_connection_at_sdk_boundary(): void
    {
        $configuration = McpClientConfiguration::stdio(
            PHP_BINARY,
            ['-r', 'exit(0);'],
            dirname(PHP_BINARY),
            [],
            static fn(): bool => true,
        );
        $sdk = $this->createMock(Client::class);
        $sdk->expects(self::once())->method('disconnect')->willThrowException(new \RuntimeException('private cleanup detail'));
        $connection = new \Evolve\Mcp\Client\McpClientConnection($sdk, $configuration);

        try {
            $connection->close();
            self::fail('Expected SDK cleanup failure.');
        } catch (McpClientException $exception) {
            self::assertSame(McpClientFailureCategory::Cleanup, $exception->category);
            self::assertSame($exception, $connection->quarantineFailure());
            self::assertFalse($connection->isReusable());
            self::assertStringNotContainsString('private', $exception->getMessage());
        }
    }

    private static function sdkServerScript(): string
    {
        return <<<'PHP'
require getcwd() . '/vendor/autoload.php';
file_put_contents($argv[1], (string) getmypid());
$builder = (new Mcp\Server\Builder())->setServerInfo('stdio-fixture', '1.0');
$builder->add(new Mcp\Schema\Tool('environment', null, ['type' => 'object'], null, null), new class implements Mcp\Server\Handler\ToolHandlerInterface {
    public function execute(array $arguments, Mcp\Server\ClientGateway $gateway): mixed {
        return getenv('MCP_ALLOWED') . ':' . (getenv('EVOLVE_MCP_AMBIENT_SECRET') === false ? 'isolated' : 'leaked');
    }
});
$builder->add(new Mcp\Schema\Tool('slow', null, ['type' => 'object'], null, null), new class implements Mcp\Server\Handler\ToolHandlerInterface {
    public function execute(array $arguments, Mcp\Server\ClientGateway $gateway): mixed { sleep(3); return 'late'; }
});
$builder->add(new Mcp\Schema\ResourceDefinition('example://document', 'document'), new class implements Mcp\Server\Handler\ResourceHandlerInterface {
    public function read(string $uri, Mcp\Server\ClientGateway $gateway): mixed { return 'resource body'; }
});
$builder->add(new Mcp\Schema\Prompt('greet'), new class implements Mcp\Server\Handler\PromptHandlerInterface {
    public function get(array $arguments, Mcp\Server\ClientGateway $gateway): mixed { return [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Hello']]]; }
});
$builder->build()->run(new Mcp\Server\Transport\StdioTransport(STDIN, STDOUT));
PHP;
    }
}
