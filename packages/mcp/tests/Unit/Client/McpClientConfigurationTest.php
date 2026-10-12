<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Unit\Client;

use Evolve\Mcp\Client\McpClientConfiguration;
use GuzzleHttp\Psr7\HttpFactory;
use Mcp\Schema\Enum\ProtocolVersion;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

final class McpClientConfigurationTest extends TestCase
{
    public function test_http_requires_explicit_https_endpoint_factories_client_and_destination_policy(): void
    {
        $http = $this->createStub(ClientInterface::class);
        $factory = new HttpFactory();
        $configuration = McpClientConfiguration::http(
            'https://localhost/mcp',
            $http,
            $factory,
            $factory,
            static fn(): bool => true,
        );

        self::assertSame('http', $configuration->mode);
        self::assertSame(ProtocolVersion::V2026_07_28, $configuration->protocolVersion);
        self::assertSame($http, $configuration->httpClient);
        self::assertSame($factory, $configuration->requestFactory);
        self::assertSame($factory, $configuration->streamFactory);
        self::assertSame(0, $configuration->retries);
    }

    public function test_http_rejects_credentials_in_url_and_unapproved_or_insecure_endpoints(): void
    {
        $http = $this->createStub(ClientInterface::class);
        $factory = new HttpFactory();
        foreach (['http://localhost/mcp', 'https://user:secret@localhost/mcp', 'https://localhost/mcp#fragment', 'https://localhost/mcp?token=secret'] as $endpoint) {
            try {
                McpClientConfiguration::http($endpoint, $http, $factory, $factory, static fn(): bool => true);
                self::fail('Invalid endpoint accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('MCP HTTP endpoint is invalid or unapproved.', $exception->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        McpClientConfiguration::http('https://localhost/mcp', $http, $factory, $factory, static fn(): bool => false);
    }

    public function test_invalid_url_is_rejected_before_calling_destination_policy(): void
    {
        $factory = new HttpFactory();
        $http = $this->createStub(ClientInterface::class);
        $approvalCalls = 0;
        try {
            McpClientConfiguration::http(
                'https://user:secret@localhost/mcp',
                $http,
                $factory,
                $factory,
                static function () use (&$approvalCalls): bool {
                    ++$approvalCalls;
                    return true;
                },
            );
            self::fail('Expected credential-bearing URL refusal.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $approvalCalls);
        }
    }
    public function test_endpoint_approval_failure_does_not_expose_callback_details(): void
    {
        $factory = new HttpFactory();
        $http = $this->createStub(ClientInterface::class);
        try {
            McpClientConfiguration::http(
                'https://localhost/mcp',
                $http,
                $factory,
                $factory,
                static function (): never {
                    throw new \RuntimeException('secret callback detail');
                },
            );
            self::fail('Expected endpoint policy failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringNotContainsString('secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }
    public function test_stdio_requires_trusted_existing_absolute_command_and_directory(): void
    {
        $configuration = McpClientConfiguration::stdio(
            PHP_BINARY,
            ['-v'],
            dirname(PHP_BINARY),
            [],
            static fn(): bool => true,
        );
        self::assertSame('stdio', $configuration->mode);
        self::assertSame(ProtocolVersion::V2025_06_18, $configuration->protocolVersion);
        self::assertSame(0, $configuration->retries);

        $this->expectException(\InvalidArgumentException::class);
        McpClientConfiguration::stdio('php', [], dirname(PHP_BINARY), [], static fn(): bool => true);
    }

    public function test_limits_and_environment_are_bounded_and_validated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        McpClientConfiguration::stdio(
            PHP_BINARY,
            [],
            dirname(PHP_BINARY),
            ['BAD=NAME' => 'x'],
            static fn(): bool => true,
        );
    }
}
