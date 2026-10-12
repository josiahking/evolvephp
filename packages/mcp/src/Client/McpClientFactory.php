<?php

declare(strict_types=1);

namespace Evolve\Mcp\Client;

use Evolve\Secret\Contracts\SecretName;
use Evolve\Secret\Contracts\SecretResolver;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\StdioTransport;
use Throwable;

/** Creates a fresh SDK client and transport after resolving this execution's secrets. */
final readonly class McpClientFactory
{
    public function connect(McpClientConfiguration $configuration, ?SecretResolver $secrets = null): McpClientConnection
    {
        if ($configuration->mode === 'stdio' && PHP_OS_FAMILY !== 'Linux') {
            throw new McpClientException(McpClientFailureCategory::Configuration, 'MCP STDIO is supported on Linux PHP only.');
        }

        $bearer = $configuration->bearerSecret === null
            ? null
            : $this->resolveSecret($secrets, $configuration->bearerSecret);
        $environment = $configuration->environment;
        foreach ($configuration->secretEnvironment as $name => $secretName) {
            $environment[$name] = $this->resolveSecret($secrets, $secretName);
        }

        if ($configuration->mode === 'http') {
            if ($configuration->httpClient === null || $configuration->requestFactory === null
                || $configuration->streamFactory === null || $configuration->endpoint === null
                || $configuration->approveDestination === null) {
                throw new McpClientException(McpClientFailureCategory::Configuration, 'MCP HTTP configuration is incomplete.');
            }
            $policy = new McpHttpPolicyClient(
                $configuration->httpClient,
                $configuration->streamFactory,
                $configuration->endpoint,
                $configuration->approveDestination,
                $configuration->maxResponseBytes,
                $bearer,
            );
            $transport = new HttpTransport(
                $configuration->endpoint,
                httpClient: $policy,
                requestFactory: $configuration->requestFactory,
                streamFactory: $configuration->streamFactory,
            );
        } else {
            if ($configuration->command === null || $configuration->workingDirectory === null) {
                throw new McpClientException(McpClientFailureCategory::Configuration, 'MCP STDIO configuration is incomplete.');
            }
            $transport = new StdioTransport(
                $configuration->command,
                $configuration->arguments,
                $configuration->workingDirectory,
                $environment,
            );
        }

        $client = Client::builder()
            ->setClientInfo('evolvephp-mcp-client', '0.1.0')
            ->setProtocolVersion($configuration->protocolVersion)
            ->setInitTimeout($configuration->initTimeoutSeconds)
            ->setRequestTimeout($configuration->requestTimeoutSeconds)
            ->setMaxRetries(0)

            ->build();

        try {
            $client->connect($transport);
        } catch (Throwable $exception) {
            try {
                $client->disconnect();
            } catch (Throwable) {
                throw new McpClientException(
                    McpClientFailureCategory::Connection,
                    'MCP connection and cleanup failed; recycle the worker.',
                    McpClientFailureCategory::Cleanup,
                );
            }
            throw new McpClientException(
                self::findPolicyCategory($exception) ?? McpClientFailureCategory::Connection,
                'MCP connection failed.',
            );
        }

        return new McpClientConnection($client, $configuration);
    }

    private function resolveSecret(?SecretResolver $secrets, SecretName $name): string
    {
        if ($secrets === null) {
            throw new McpClientException(McpClientFailureCategory::Secret, 'MCP secret resolver is required.');
        }
        try {
            $value = $secrets->resolve($name)?->value();
        } catch (Throwable) {
            throw new McpClientException(McpClientFailureCategory::Secret, 'MCP secret resolution failed.');
        }
        if (!is_string($value) || $value === '' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new McpClientException(McpClientFailureCategory::Secret, 'MCP secret is missing or invalid.');
        }
        return $value;
    }

    private static function findPolicyCategory(Throwable $exception): ?McpClientFailureCategory
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof McpClientException) {
                return $cause->category;
            }
        }
        return null;
    }
}
